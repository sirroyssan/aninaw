<?php
/**
 * Closes the layout opened by header.php and loads scripts.
 * Optional: $pageScripts (list of asset paths under assets/) for page-specific JavaScript.
 */
declare(strict_types=1);

$pageScripts = $pageScripts ?? [];
?>
<?php if ($isAppLayout): ?>
        </main>
        <footer class="app-footer">
            <span>&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?> &middot; <?= e(APP_TAGLINE) ?></span>
        </footer>
    </div>
</div>
<form id="logout-form" method="post" action="<?= e(url('logout.php')) ?>" class="d-none">
    <?= csrf_field() ?>
</form>
<?php else: ?>
</main>
<?php endif; ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($pageScripts as $script): ?>
<script src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
