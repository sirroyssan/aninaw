<?php
/**
 * Minimal PDF text extractor (no external tools or libraries).
 *
 * Reads the text layer of PDFs produced by browsers' "Save as PDF", office suites and
 * reporting tools: FlateDecode streams, object streams, Type0/Identity-H fonts with
 * ToUnicode maps and simple (WinAnsi) fonts, text positioning and form XObjects.
 * It returns positioned text lines per page; it does not render or OCR, so scanned
 * PDFs (images only) yield no text.
 */
declare(strict_types=1);

final class PdfName
{
    public function __construct(public readonly string $name) {}
}

final class PdfRef
{
    public function __construct(public readonly int $num) {}
}

final class PdfDict
{
    /** @param array<string, mixed> $v */
    public function __construct(public array $v = [], public ?string $stream = null) {}

    public function get(string $key): mixed
    {
        return $this->v[$key] ?? null;
    }
}

final class PdfStr
{
    public function __construct(public readonly string $bytes) {}
}

final class PdfOp
{
    public function __construct(public readonly string $op) {}
}

final class PdfTextExtractor
{
    private const MAX_OBJECTS = 20000;
    private const MAX_DEPTH = 8;

    /** @var array<int, int> object number => byte offset of "N G obj" */
    private array $offsets = [];
    /** @var array<int, mixed> */
    private array $cache = [];
    /** @var array<int, array{0:int,1:int}> object number => [object stream number, index] */
    private array $inStream = [];
    /** @var array<string, array> font cache by object key */
    private array $fonts = [];
    private int $pos = 0;
    private string $buf = '';

    public function __construct(private readonly string $data)
    {
        if (!str_starts_with(ltrim(substr($data, 0, 1024)), '%PDF-') && !str_contains(substr($data, 0, 1024), '%PDF-')) {
            throw new RuntimeException('Not a PDF file.');
        }
        if (preg_match('#/Encrypt\s#', $data)) {
            throw new RuntimeException('The PDF is password-protected or encrypted.');
        }
        preg_match_all('/(?<![0-9])(\d{1,7})\s+(\d{1,5})\s+obj\b/', $data, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $i => [$num]) {
            $this->offsets[(int) $num] = $matches[0][$i][1]; // later definitions (incremental updates) win
        }
        if (count($this->offsets) > self::MAX_OBJECTS) {
            throw new RuntimeException('The PDF is too large to read.');
        }
        $this->indexObjectStreams();
    }

    /**
     * Text lines per page: [[['y' => float, 'cells' => [['x' => float, 'text' => string], ...], 'text' => string], ...], ...]
     * Lines are ordered top to bottom, cells left to right.
     */
    public function pages(): array
    {
        $pages = [];
        foreach ($this->pageDicts() as $page) {
            $items = [];
            $resources = $this->resolve($page->get('Resources'));
            $content = $this->resolve($page->get('Contents'));
            $streams = $content instanceof PdfDict ? [$content] : array_map(fn($c) => $this->resolve($c), is_array($content) ? $content : []);
            $data = '';
            foreach ($streams as $stream) {
                if ($stream instanceof PdfDict) {
                    $data .= $this->streamData($stream) . "\n";
                }
            }
            $this->runContent($data, $resources instanceof PdfDict ? $resources : new PdfDict(), [1, 0, 0, 1, 0, 0], $items, 0);
            $pages[] = self::groupLines($items);
        }
        return $pages;
    }

    // ------------------------------------------------------------------
    // Objects
    // ------------------------------------------------------------------

    private function indexObjectStreams(): void
    {
        foreach (array_keys($this->offsets) as $num) {
            $head = substr($this->data, $this->offsets[$num], 400);
            if (!str_contains($head, '/ObjStm')) {
                continue;
            }
            $stream = $this->object($num);
            if (!$stream instanceof PdfDict || ($stream->get('Type') instanceof PdfName ? $stream->get('Type')->name : '') !== 'ObjStm') {
                continue;
            }
            $count = (int) $this->resolve($stream->get('N'));
            $first = (int) $this->resolve($stream->get('First'));
            $data = $this->streamData($stream);
            $header = preg_split('/\s+/', trim(substr($data, 0, $first))) ?: [];
            for ($i = 0; $i < $count && isset($header[$i * 2 + 1]); $i++) {
                $objNum = (int) $header[$i * 2];
                if (!isset($this->offsets[$objNum])) {
                    $this->inStream[$objNum] = [$num, $i];
                }
            }
        }
    }

    private function object(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $this->cache[$num] = null; // guards against reference cycles
        [$buf, $pos] = [$this->buf, $this->pos]; // objects load lazily, possibly while a content stream is being read
        if (isset($this->offsets[$num])) {
            $value = $this->parseIndirect($this->offsets[$num]);
        } elseif (isset($this->inStream[$num])) {
            $value = $this->fromObjectStream(...$this->inStream[$num]);
        } else {
            $value = null;
        }
        [$this->buf, $this->pos] = [$buf, $pos];
        return $this->cache[$num] = $value;
    }

    private function fromObjectStream(int $streamNum, int $index): mixed
    {
        $stream = $this->object($streamNum);
        if (!$stream instanceof PdfDict) {
            return null;
        }
        $first = (int) $this->resolve($stream->get('First'));
        $data = $this->streamData($stream);
        $header = preg_split('/\s+/', trim(substr($data, 0, $first))) ?: [];
        $offset = (int) ($header[$index * 2 + 1] ?? -1);
        if ($offset < 0) {
            return null;
        }
        return $this->parseAt($data, $first + $offset);
    }

    private function parseIndirect(int $offset): mixed
    {
        $this->buf = $this->data;
        $this->pos = $offset;
        $this->token(); $this->token(); $this->token(); // "N G obj"
        $value = $this->value();
        if ($value instanceof PdfDict) {
            $save = $this->pos;
            $this->skipSpace();
            if (substr($this->buf, $this->pos, 6) === 'stream') {
                $start = $this->pos + 6;
                if (($this->buf[$start] ?? '') === "\r") {
                    $start++;
                }
                if (($this->buf[$start] ?? '') === "\n") {
                    $start++;
                }
                $length = $value->get('Length');
                $length = $length instanceof PdfRef ? $this->object($length->num) : $length;
                $end = strpos($this->data, 'endstream', $start);
                if (is_int($length) && $length >= 0 && $start + $length <= strlen($this->data)
                    && preg_match('/^\s*endstream/', substr($this->data, $start + $length, 20))) {
                    $value->stream = substr($this->data, $start, $length);
                } elseif ($end !== false) {
                    $value->stream = rtrim(substr($this->data, $start, $end - $start), "\r\n");
                }
            } else {
                $this->pos = $save;
            }
        }
        return $value;
    }

    private function parseAt(string $data, int $offset): mixed
    {
        $this->buf = $data;
        $this->pos = $offset;
        return $this->value();
    }

    private function resolve(mixed $value, int $depth = 0): mixed
    {
        while ($value instanceof PdfRef && $depth++ < 32) {
            $value = $this->object($value->num);
        }
        return $value;
    }

    private function streamData(PdfDict $dict): string
    {
        $data = (string) $dict->stream;
        $filters = $this->resolve($dict->get('Filter'));
        $filters = $filters instanceof PdfName ? [$filters] : (is_array($filters) ? $filters : []);
        foreach ($filters as $filter) {
            $name = $this->resolve($filter);
            $name = $name instanceof PdfName ? $name->name : '';
            if ($name === 'FlateDecode' || $name === 'Fl') {
                $out = @gzuncompress($data);
                if ($out === false) {
                    $out = @gzinflate(substr($data, 2));
                }
                if ($out === false) {
                    return '';
                }
                $data = $out;
                $params = $this->resolve($dict->get('DecodeParms'));
                if ($params instanceof PdfDict && (int) $this->resolve($params->get('Predictor')) >= 10) {
                    $data = self::pngUnpredict($data, (int) ($this->resolve($params->get('Columns')) ?? 1));
                }
            } elseif ($name === 'ASCIIHexDecode' || $name === 'AHx') {
                $data = (string) hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', strtok($data, '>')) ?: '');
            } else {
                return ''; // images and other encodings carry no text
            }
        }
        return $data;
    }

    private static function pngUnpredict(string $data, int $columns): string
    {
        $out = '';
        $row = $columns + 1;
        $prev = str_repeat("\0", $columns);
        for ($i = 0; $i + $row <= strlen($data); $i += $row) {
            $type = ord($data[$i]);
            $line = substr($data, $i + 1, $columns);
            $cur = '';
            for ($j = 0; $j < $columns; $j++) {
                $left = $j > 0 ? ord($cur[$j - 1]) : 0;
                $up = ord($prev[$j]);
                $c = ord($line[$j]);
                $cur .= chr(match ($type) { 1 => $c + $left, 2 => $c + $up, 3 => $c + intdiv($left + $up, 2), default => $c } & 0xFF);
            }
            $out .= $cur;
            $prev = $cur;
        }
        return $out;
    }

    /** @return PdfDict[] */
    private function pageDicts(): array
    {
        $root = null;
        if (preg_match_all('#/Root\s+(\d+)\s+\d+\s+R#', $this->data, $m)) {
            $root = $this->object((int) end($m[1]));
        }
        $pages = [];
        if ($root instanceof PdfDict) {
            $this->collectPages($this->resolve($root->get('Pages')), $pages, 0);
        }
        if (!$pages) { // damaged catalog: fall back to every /Type /Page object in file order
            foreach (array_keys($this->offsets) as $num) {
                $object = $this->object($num);
                if ($object instanceof PdfDict && $object->get('Type') instanceof PdfName && $object->get('Type')->name === 'Page') {
                    $pages[] = $object;
                }
            }
        }
        return $pages;
    }

    private function collectPages(mixed $node, array &$pages, int $depth, ?PdfDict $inherited = null): void
    {
        if (!$node instanceof PdfDict || $depth > 64 || count($pages) > 2000) {
            return;
        }
        $type = $node->get('Type') instanceof PdfName ? $node->get('Type')->name : '';
        $resources = $node->get('Resources') ?? $inherited?->get('Resources');
        if ($type === 'Page' || ($type === '' && $node->get('Contents') !== null)) {
            if ($node->get('Resources') === null && $resources !== null) {
                $node->v['Resources'] = $resources;
            }
            $pages[] = $node;
            return;
        }
        $kids = $this->resolve($node->get('Kids'));
        foreach (is_array($kids) ? $kids : [] as $kid) {
            $this->collectPages($this->resolve($kid), $pages, $depth + 1, new PdfDict(['Resources' => $resources]));
        }
    }

    // ------------------------------------------------------------------
    // Tokenizer / parser
    // ------------------------------------------------------------------

    private function skipSpace(): void
    {
        $len = strlen($this->buf);
        while ($this->pos < $len) {
            $c = $this->buf[$this->pos];
            if ($c === '%') {
                while ($this->pos < $len && $this->buf[$this->pos] !== "\n" && $this->buf[$this->pos] !== "\r") {
                    $this->pos++;
                }
            } elseif (strpos(" \t\r\n\f\0", $c) !== false) {
                $this->pos++;
            } else {
                return;
            }
        }
    }

    /** Next raw token for header skipping. */
    private function token(): string
    {
        $this->skipSpace();
        $start = $this->pos;
        while ($this->pos < strlen($this->buf) && strpos(" \t\r\n\f\0()<>[]{}/%", $this->buf[$this->pos]) === false) {
            $this->pos++;
        }
        return substr($this->buf, $start, $this->pos - $start);
    }

    private function value(): mixed
    {
        $this->skipSpace();
        $len = strlen($this->buf);
        if ($this->pos >= $len) {
            return null;
        }
        $c = $this->buf[$this->pos];
        if ($c === '/') {
            $this->pos++;
            $start = $this->pos;
            while ($this->pos < $len && strpos(" \t\r\n\f\0()<>[]{}/%", $this->buf[$this->pos]) === false) {
                $this->pos++;
            }
            return new PdfName(preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn($m) => chr(hexdec($m[1])), substr($this->buf, $start, $this->pos - $start)));
        }
        if ($c === '<' && ($this->buf[$this->pos + 1] ?? '') === '<') {
            $this->pos += 2;
            $dict = [];
            for ($guard = 0; $guard < 100000; $guard++) {
                $this->skipSpace();
                if ($this->pos >= $len) {
                    break;
                }
                if (substr($this->buf, $this->pos, 2) === '>>') {
                    $this->pos += 2;
                    break;
                }
                $key = $this->value();
                if (!$key instanceof PdfName) {
                    $this->pos++;
                    continue;
                }
                $dict[$key->name] = $this->value();
            }
            return new PdfDict($dict);
        }
        if ($c === '<') {
            $end = strpos($this->buf, '>', $this->pos);
            $end = $end === false ? $len : $end;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($this->buf, $this->pos + 1, $end - $this->pos - 1));
            $this->pos = $end + 1;
            if (strlen($hex) % 2) {
                $hex .= '0';
            }
            return new PdfStr((string) hex2bin($hex));
        }
        if ($c === '(') {
            return new PdfStr($this->literalString());
        }
        if ($c === '[') {
            $this->pos++;
            $items = [];
            for ($guard = 0; $guard < 1000000; $guard++) {
                $this->skipSpace();
                if ($this->pos >= $len) {
                    break;
                }
                if ($this->buf[$this->pos] === ']') {
                    $this->pos++;
                    break;
                }
                $items[] = $this->value();
            }
            return $items;
        }
        if ($c === ']' || $c === '>' || $c === ')' || $c === '{' || $c === '}') {
            $this->pos++;
            return new PdfOp($c);
        }
        $word = $this->token();
        if ($word === '') {
            $this->pos++;
            return null;
        }
        if (is_numeric($word)) {
            // "N G R" reference?
            if (ctype_digit($word) && preg_match('/\G\s+(\d+)\s+R(?![A-Za-z])/', $this->buf, $m, 0, $this->pos)) {
                $this->pos += strlen($m[0]);
                return new PdfRef((int) $word);
            }
            return str_contains($word, '.') ? (float) $word : (int) $word;
        }
        return match ($word) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => new PdfOp($word),
        };
    }

    private function literalString(): string
    {
        $len = strlen($this->buf);
        $this->pos++;
        $depth = 1;
        $out = '';
        while ($this->pos < $len) {
            $c = $this->buf[$this->pos++];
            if ($c === '\\') {
                $n = $this->buf[$this->pos++] ?? '';
                if (isset(['n' => 1, 'r' => 1, 't' => 1, 'b' => 1, 'f' => 1][$n])) {
                    $out .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c"][$n];
                } elseif ($n >= '0' && $n <= '7') {
                    $oct = $n;
                    for ($i = 0; $i < 2 && ($this->buf[$this->pos] ?? '') >= '0' && ($this->buf[$this->pos] ?? '') <= '7'; $i++) {
                        $oct .= $this->buf[$this->pos++];
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                } elseif ($n === "\r") {
                    if (($this->buf[$this->pos] ?? '') === "\n") {
                        $this->pos++;
                    }
                } elseif ($n !== "\n") {
                    $out .= $n;
                }
            } elseif ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    break;
                }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Content streams
    // ------------------------------------------------------------------

    private static function multiply(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    private function runContent(string $data, PdfDict $resources, array $ctm, array &$items, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $saveBuf = $this->buf;
        $savePos = $this->pos;
        $this->buf = $data;
        $this->pos = 0;
        $len = strlen($data);
        $stack = [];
        $gstack = [];
        $tm = $tlm = [1, 0, 0, 1, 0, 0];
        $leading = 0.0;
        $font = null;
        $fontSize = 1.0;
        $charSpacing = $wordSpacing = 0.0;
        $scale = 1.0;

        // Shows one string: records an item from the current text position to the advanced one.
        $show = function (string $bytes) use (&$items, &$tm, &$ctm, &$fontSize, &$font, &$charSpacing, &$wordSpacing, &$scale): void {
            [$text, $advance] = $this->decode($bytes, $font, $fontSize, $charSpacing, $wordSpacing);
            $start = self::multiply([$fontSize, 0, 0, $fontSize, 0, 0], self::multiply($tm, $ctm));
            $tm = self::multiply([1, 0, 0, 1, $advance * $scale, 0], $tm);
            if ($text === '') {
                return;
            }
            $end = self::multiply($tm, $ctm);
            $items[] = ['x' => $start[4], 'end' => $end[4], 'y' => $start[5], 'size' => abs($start[3]) ?: abs($start[1]) ?: 1, 'text' => $text];
        };

        while ($this->pos < $len) {
            $value = $this->value();
            if (!$value instanceof PdfOp) {
                $stack[] = $value;
                if (count($stack) > 1000) {
                    $stack = [];
                }
                continue;
            }
            $op = $value->op;
            $num = static fn(int $i) => (float) (is_numeric($stack[$i] ?? null) ? $stack[$i] : 0);
            switch ($op) {
                case 'q':
                    $gstack[] = $ctm;
                    break;
                case 'Q':
                    $ctm = array_pop($gstack) ?? $ctm;
                    break;
                case 'cm':
                    if (count($stack) >= 6) {
                        $n = count($stack);
                        $ctm = self::multiply(array_map(static fn($v) => (float) $v, array_slice($stack, $n - 6, 6)), $ctm);
                    }
                    break;
                case 'BT':
                    $tm = $tlm = [1, 0, 0, 1, 0, 0];
                    break;
                case 'ET':
                    break;
                case 'Tc':
                    $charSpacing = $num(count($stack) - 1);
                    break;
                case 'Tw':
                    $wordSpacing = $num(count($stack) - 1);
                    break;
                case 'Tz':
                    $scale = $num(count($stack) - 1) / 100;
                    break;
                case 'Tf':
                    $name = $stack[count($stack) - 2] ?? null;
                    $fontSize = (float) (end($stack) ?: 1);
                    $font = $name instanceof PdfName ? $this->font($resources, $name->name) : null;
                    break;
                case 'TL':
                    $leading = $num(count($stack) - 1);
                    break;
                case 'Td':
                case 'TD':
                    $tx = $num(count($stack) - 2);
                    $ty = $num(count($stack) - 1);
                    if ($op === 'TD') {
                        $leading = -$ty;
                    }
                    $tlm = self::multiply([1, 0, 0, 1, $tx, $ty], $tlm);
                    $tm = $tlm;
                    break;
                case 'Tm':
                    if (count($stack) >= 6) {
                        $n = count($stack);
                        $tm = $tlm = array_map(static fn($v) => (float) $v, array_slice($stack, $n - 6, 6));
                    }
                    break;
                case 'T*':
                    $tlm = self::multiply([1, 0, 0, 1, 0, -$leading], $tlm);
                    $tm = $tlm;
                    break;
                case "'":
                case '"':
                    $tlm = self::multiply([1, 0, 0, 1, 0, -$leading], $tlm);
                    $tm = $tlm;
                    if ($op === '"') {
                        $wordSpacing = $num(0);
                        $charSpacing = $num(1);
                    }
                    // no break: show the string
                case 'Tj':
                    $string = end($stack);
                    if ($string instanceof PdfStr) {
                        $show($string->bytes);
                    }
                    break;
                case 'TJ':
                    $array = end($stack);
                    foreach (is_array($array) ? $array : [] as $part) {
                        if ($part instanceof PdfStr) {
                            $show($part->bytes);
                        } elseif (is_numeric($part)) {
                            $tm = self::multiply([1, 0, 0, 1, -$part / 1000 * $fontSize * $scale, 0], $tm);
                        }
                    }
                    break;
                case 'Do':
                    $name = end($stack);
                    $xobjects = $this->resolve($resources->get('XObject'));
                    $xobject = $name instanceof PdfName && $xobjects instanceof PdfDict ? $this->resolve($xobjects->get($name->name)) : null;
                    if ($xobject instanceof PdfDict && ($xobject->get('Subtype') instanceof PdfName) && $xobject->get('Subtype')->name === 'Form') {
                        $matrix = $this->resolve($xobject->get('Matrix'));
                        $formCtm = is_array($matrix) && count($matrix) === 6 ? self::multiply(array_map(static fn($v) => (float) $this->resolve($v), $matrix), $ctm) : $ctm;
                        $formResources = $this->resolve($xobject->get('Resources'));
                        $content = $this->streamData($xobject);
                        $innerPos = $this->pos;
                        $innerBuf = $this->buf;
                        $this->runContent($content, $formResources instanceof PdfDict ? $formResources : $resources, $formCtm, $items, $depth + 1);
                        $this->buf = $innerBuf;
                        $this->pos = $innerPos;
                    }
                    break;
                case 'BI': // inline image: skip to EI
                    $end = strpos($this->buf, 'EI', $this->pos);
                    while ($end !== false && !preg_match('/\sEI(\s|$)/', substr($this->buf, $end - 1, 4))) {
                        $end = strpos($this->buf, 'EI', $end + 2);
                    }
                    $this->pos = $end === false ? $len : $end + 2;
                    break;
            }
            $stack = [];
        }
        $this->buf = $saveBuf;
        $this->pos = $savePos;
    }

    // ------------------------------------------------------------------
    // Fonts and text decoding
    // ------------------------------------------------------------------

    private function font(PdfDict $resources, string $name): ?array
    {
        $fonts = $this->resolve($resources->get('Font'));
        $ref = $fonts instanceof PdfDict ? $fonts->get($name) : null;
        $key = $ref instanceof PdfRef ? 'r' . $ref->num : 'n' . $name . spl_object_id($resources);
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        $dict = $this->resolve($ref);
        if (!$dict instanceof PdfDict) {
            return null;
        }
        $subtype = $dict->get('Subtype') instanceof PdfName ? $dict->get('Subtype')->name : '';
        $font = ['composite' => $subtype === 'Type0', 'map' => [], 'bytes' => $subtype === 'Type0' ? 2 : 1, 'widths' => [], 'default' => 500.0];
        if ($font['composite']) {
            $descendants = $this->resolve($dict->get('DescendantFonts'));
            $cid = is_array($descendants) ? $this->resolve($descendants[0] ?? null) : null;
            if ($cid instanceof PdfDict) {
                $font['default'] = (float) ($this->resolve($cid->get('DW')) ?? 1000);
                $w = $this->resolve($cid->get('W'));
                $w = is_array($w) ? array_map(fn($v) => $this->resolve($v), $w) : [];
                for ($i = 0; $i < count($w) && $i < 200000;) {
                    $first = (int) $w[$i];
                    if (is_array($w[$i + 1] ?? null)) {
                        foreach ($w[$i + 1] as $k => $width) {
                            $font['widths'][$first + $k] = (float) $this->resolve($width);
                        }
                        $i += 2;
                    } else {
                        $last = min((int) ($w[$i + 1] ?? $first), $first + 65535);
                        for ($code = $first; $code <= $last; $code++) {
                            $font['widths'][$code] = (float) ($w[$i + 2] ?? $font['default']);
                        }
                        $i += 3;
                    }
                }
            }
        } else {
            $firstChar = (int) ($this->resolve($dict->get('FirstChar')) ?? 0);
            $widths = $this->resolve($dict->get('Widths'));
            foreach (is_array($widths) ? $widths : [] as $k => $width) {
                $font['widths'][$firstChar + $k] = (float) $this->resolve($width);
            }
        }
        $toUnicode = $this->resolve($dict->get('ToUnicode'));
        if ($toUnicode instanceof PdfDict) {
            [$font['map'], $bytes] = $this->parseCMap($this->streamData($toUnicode));
            if ($bytes) {
                $font['bytes'] = $bytes;
            }
        }
        return $this->fonts[$key] = $font;
    }

    /** @return array{0: array<int, string>, 1: int} code => UTF-8 text, code length in bytes */
    private function parseCMap(string $cmap): array
    {
        $map = [];
        $bytes = 0;
        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m)) {
            $bytes = intdiv(strlen($m[1]) + 1, 2);
        }
        $utf = static function (string $hex): string {
            $raw = (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
            return (string) mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
        };
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $src, $dst]) {
                $map[hexdec($src)] = $utf($dst);
            }
        }
        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $block, $ranges, PREG_SET_ORDER);
            foreach ($ranges as [, $lo, $hi, $dst]) {
                $lo = hexdec($lo);
                $hi = min(hexdec($hi), $lo + 65535);
                if ($dst[0] === '[') {
                    preg_match_all('/<([0-9A-Fa-f]*)>/', $dst, $list);
                    foreach ($list[1] as $i => $hex) {
                        if ($lo + $i <= $hi) {
                            $map[$lo + $i] = $utf($hex);
                        }
                    }
                    continue;
                }
                $hex = trim($dst, '<>');
                $base = hexdec(substr($hex, -4) ?: '0');
                $prefix = strlen($hex) > 4 ? substr($hex, 0, -4) : '';
                for ($code = $lo; $code <= $hi; $code++) {
                    $map[$code] = $utf($prefix . str_pad(dechex($base + $code - $lo), 4, '0', STR_PAD_LEFT));
                }
            }
        }
        return [$map, $bytes];
    }

    /**
     * Decodes a shown string. Returns [UTF-8 text, horizontal advance in unscaled text space].
     * A composite font without a Unicode map yields no text (glyph IDs are not characters).
     */
    private function decode(string $bytes, ?array $font, float $size, float $charSpacing, float $wordSpacing): array
    {
        $font ??= ['composite' => false, 'map' => [], 'bytes' => 1, 'widths' => [], 'default' => 500.0];
        $out = '';
        $advance = 0.0;
        $width = $font['composite'] ? max(1, (int) $font['bytes']) : 1;
        for ($i = 0; $i + $width <= strlen($bytes); $i += $width) {
            $code = $width === 1 ? ord($bytes[$i]) : (int) hexdec(bin2hex(substr($bytes, $i, $width)));
            if (isset($font['map'][$code])) {
                $char = $font['map'][$code];
            } else {
                $char = $font['composite'] ? '' : (string) mb_convert_encoding($bytes[$i], 'UTF-8', 'Windows-1252');
            }
            $out .= $char;
            $advance += ($font['widths'][$code] ?? $font['default']) / 1000 * $size + $charSpacing + ($width === 1 && $code === 32 ? $wordSpacing : 0);
        }
        return [$out, $advance];
    }

    // ------------------------------------------------------------------
    // Layout
    // ------------------------------------------------------------------

    /** Groups positioned text into lines (top to bottom) of cells (left to right). */
    private static function groupLines(array $items): array
    {
        $items = array_values(array_filter($items, static fn($i) => trim(str_replace("\u{a0}", ' ', $i['text'])) !== ''));
        usort($items, static fn($a, $b) => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);
        $lines = [];
        foreach ($items as $item) {
            $tolerance = max(2.0, $item['size'] * 0.4);
            $index = array_key_last($lines);
            if ($index !== null && abs($lines[$index]['y'] - $item['y']) <= $tolerance) {
                $lines[$index]['cells'][] = $item;
            } else {
                $lines[] = ['y' => $item['y'], 'cells' => [$item]];
            }
        }
        foreach ($lines as &$line) {
            usort($line['cells'], static fn($a, $b) => $a['x'] <=> $b['x']);
            // Glyphs drawn one by one are joined into words; small gaps are spaces, wide gaps start a new cell (column).
            $cells = [];
            foreach ($line['cells'] as $item) {
                $index = array_key_last($cells);
                $gap = $index === null ? INF : $item['x'] - $cells[$index]['end'];
                if ($gap < $item['size'] * 0.15) {
                    $cells[$index]['text'] .= $item['text'];
                    $cells[$index]['end'] = max($cells[$index]['end'], $item['end']);
                } elseif ($gap < $item['size'] * 1.2) {
                    $cells[$index]['text'] .= ' ' . $item['text'];
                    $cells[$index]['end'] = max($cells[$index]['end'], $item['end']);
                } else {
                    $cells[] = $item;
                }
            }
            $line['cells'] = array_map(static fn($c) => ['x' => round($c['x'], 1), 'text' => trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $c['text'])))], $cells);
            $line['cells'] = array_values(array_filter($line['cells'], static fn($c) => $c['text'] !== ''));
            $line['text'] = implode(' ', array_column($line['cells'], 'text'));
        }
        return $lines;
    }
}

/** Text lines per page of a PDF file. Throws RuntimeException with a user-safe message. */
function pdf_text_lines(string $path): array
{
    $data = file_get_contents($path);
    if ($data === false || $data === '') {
        throw new RuntimeException('The file could not be read.');
    }
    return (new PdfTextExtractor($data))->pages();
}
