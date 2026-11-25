<?php
declare(strict_types=1);

namespace AstrX\Site\Controller;

use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Site\DefaultTemplateContext;
use AstrX\User\Controller\LoginController;
use AstrX\User\UserService;
use AstrX\User\UserSession;
use AstrX\Kernel\AbstractController;

/**
 * User section root (/en/user or /it/utente).
 *
 * Renders directly rather than redirecting:
 *   - Logged in  → shows user home content
 *   - Not logged in → shows login form
 *
 * Token verification links (?_token=xxx&_uid=yyy) are still handled here
 * and redirect out after processing.
 */
final class UserController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly UserSession           $session,
        private readonly LoginController        $login,
        private readonly UserService           $userService,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        // --- Token verification (email links) --------------------------------
        $rawToken = $this->request->query()->get('_token');
        $hexUid   = $this->request->query()->get('_uid');

        if (is_string($rawToken) && $rawToken !== '' && is_string($hexUid) && $hexUid !== '') {
            $verifyResult = $this->userService->verifyToken($hexUid, $rawToken);
            $verifyResult->drainTo($this->collector);

            if ($verifyResult->isOk()) {
                $tokenType = $verifyResult->unwrap();

                if ($tokenType === UserService::TOKEN_DELETE) {
                    $this->userService->delete(
                        hexId:       $hexUid,
                        mode:        \AstrX\User\DeletionMode::SOFT_REDACT,
                        tokenUnlock: true,
                    );
                    $this->session->logout();
                } elseif (
                    $this->session->isLoggedIn() &&
                    $this->session->userId() === $hexUid
                ) {
                    // Only email verify/change tokens actually confirm the address
                    // (verifyToken sets DB `verified` for those only) — marking the
                    // session verified on a RECOVER token would desync session vs DB.
                    if ($tokenType === UserService::TOKEN_EMAIL_VERIFY
                        || $tokenType === UserService::TOKEN_EMAIL_CHANGE) {
                        $this->session->markVerified();
                    }
                    if ($tokenType === UserService::TOKEN_RECOVER) {
                        // Already logged in and recovering → grant the reset unlock.
                        $_SESSION['_pw_reset_until'] = time() + 900;
                    }
                } elseif (!$this->session->isLoggedIn() && $tokenType === UserService::TOKEN_RECOVER) {
                    // Logged-out password recovery. SECURITY: a valid
                    // single-use token proves control of the recovery address, but
                    // clicking the link must NOT establish a session for whoever
                    // opened it — otherwise an attacker who mails their OWN valid
                    // link to a victim gets the victim operating inside the
                    // attacker's account (login CSRF / session fixation). Instead
                    // grant a narrow, one-shot, 15-minute capability to set a NEW
                    // password for THIS uid only (no login), and send them to the
                    // recovery page's set-password step. A session is established
                    // only after a new password is chosen — which the attacker
                    // cannot know. (Handled in RecoverController::renderResetForm.)
                    $_SESSION['_pw_reset_uid']   = $hexUid;
                    $_SESSION['_pw_reset_until'] = time() + 900;
                    header('Location: ' . $this->urlGen->toPage($this->t->t('WORDING_RECOVER')));
                    exit;
                }

                // Redirect to settings for recover/email actions, main for delete
                $dest = $tokenType === UserService::TOKEN_DELETE
                    ? $this->t->t('WORDING_MAIN')
                    : $this->t->t('WORDING_SETTINGS');

                header('Location: ' . $this->urlGen->toPage($dest));
                exit;
            }
            // Fall through on failed token — show normal page with errors visible
        }

        if (!$this->session->isLoggedIn()) {
            $this->ctx->set('logged_in', false);
            $this->ctx->set('user_login_form', 'user/login');
            return $this->login->handle();
        }

        $this->renderHome();
        return $this->ok();
    }

    private function renderHome(): void
    {
        $this->ctx->set('logged_in',             true);
        $this->ctx->set('username',              $this->session->username());
        $this->ctx->set('user_welcome_heading',  $this->t->t('user.home.heading'));
        $this->ctx->set('user_welcome_body',     $this->t->t('user.home.body'));
        $this->ctx->set('user_profile_heading',  $this->t->t('user.home.profile_heading'));
        $this->ctx->set('user_profile_text',     $this->t->t('user.home.profile_text'));
        $this->ctx->set('user_settings_heading', $this->t->t('user.home.settings_heading'));
        $this->ctx->set('user_settings_text',    $this->t->t('user.home.settings_text'));
        $this->ctx->set('profile_url',           $this->urlGen->toPage($this->t->t('WORDING_PROFILE')));
        $this->ctx->set('settings_url',          $this->urlGen->toPage($this->t->t('WORDING_SETTINGS')));
        $this->ctx->set('logout_url',            $this->urlGen->toPage($this->t->t('WORDING_LOGOUT')));
    }
}
