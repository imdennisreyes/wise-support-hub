<main class="login-wrap">
    <section class="login-brand-panel">
        <div>
            <span class="brand-mark brand-mark-lg"><i class="bi bi-life-preserver"></i></span>
            <p class="eyebrow mt-4">Wise Operations</p>
            <h1>Support Hub</h1>
            <p>Your centralized source for system support, troubleshooting, and SOPs.</p>
        </div>
        <div class="login-support-note"><i class="bi bi-shield-check"></i><span>Authorized internal users only<br><small>Activity may be logged for operational support.</small></span></div>
    </section>
    <section class="login-form-panel">
        <div class="login-form-box">
            <h2>Welcome back</h2>
            <p class="text-secondary">Sign in to access support documentation.</p>
            <?php if (!empty($error)): ?><div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?></div><?php endif; ?>
            <form method="post" action="/login" class="needs-validation" novalidate data-loading-form>
                <?= csrf_field() ?>
                <div class="mb-3"><label class="form-label" for="email">Email address</label><input class="form-control form-control-lg" id="email" name="email" type="email" value="<?= e($email ?? '') ?>" autocomplete="username" required><div class="invalid-feedback">Enter a valid email address.</div></div>
                <div class="mb-4"><label class="form-label" for="password">Password</label><div class="input-group input-group-lg"><input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle aria-label="Show password" title="Show password"><i class="bi bi-eye"></i></button><div class="invalid-feedback">Enter your password.</div></div></div>
                <button class="btn btn-primary btn-lg w-100" type="submit" data-loading-text="Signing in..."><span>Sign in</span></button>
            </form>
        </div>
    </section>
</main>
