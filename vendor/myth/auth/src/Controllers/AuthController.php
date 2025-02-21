<?php

namespace Myth\Auth\Controllers;

use Google_Client;
use CodeIgniter\Controller;
use CodeIgniter\Session\Session;
use Myth\Auth\Config\Auth as AuthConfig;
use Myth\Auth\Entities\User;
use Myth\Auth\Models\UserModel;
use App\Models\Admin\ProjectModel;

class AuthController extends Controller
{
    protected $auth;

    /**
     * @var AuthConfig
     */
    protected $config;

    /**
     * @var Session
     */
    protected $session;

    protected $projectModel;
    protected $user;
    protected $googleClient;
    protected $userDB;


    public function __construct()
    {
        // Most services in this controller require
        // the session to be started - so fire it up!
        $this->session = service('session');

        $this->config = config('Auth');
        $this->auth   = service('authentication');
        $this->projectModel = new ProjectModel();
        $this->userDB = new UserModel();
        $this->user = $this->auth->user();
        $this->googleClient = new Google_Client();
        $this->googleClient->setClientId('68235445122-vho58k8ute13dv50g1s2m8jbcc5ufga5.apps.googleusercontent.com');
        $this->googleClient->setClientSecret('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');
        $this->googleClient->setRedirectUri('http://localhost:8080/kb/authorization');
        $this->googleClient->addScope('email');
        $this->googleClient->addScope('profile');
    }

    //--------------------------------------------------------------------
    // Login/out
    //--------------------------------------------------------------------

    /**
     * Displays the login form, or redirects
     * the user to their destination/home if
     * they are already logged in.
     */
    public function login()
    {
        // No need to show a login form if the user
        // is already logged in.
        if ($this->auth->check()) {
            $redirectURL = session('redirect_url') ?? site_url('/');
            unset($_SESSION['redirect_url']);
            return redirect()->to($redirectURL);
        }

        // Set a return URL if none is specified
        $_SESSION['redirect_url'] = session('redirect_url') ?? previous_url() ?? site_url('/');

        return $this->_render($this->config->views['login'], ['config' => $this->config, 'title' => 'Virtusee | Login', 'link' => $this->googleClient->createAuthUrl()]);
    }

    /**
     * Attempts to verify the user's credentials
     * through a POST request.
     */
    public function attemptLogin()
    {
        if ($this->request->getVar('credential') === null) {
            $rules = [
                'login'    => 'required',
                'password' => 'required',
            ];
            if ($this->config->validFields === ['email']) {
                $rules['login'] .= '|valid_email';
            }
            if (!$this->validate($rules)) {
                return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
            }

            $login    = $this->request->getPost('login');
            $password = $this->request->getPost('password');
            $remember = (bool) $this->request->getPost('remember');

            // Determine credential type
            $type = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

            // Try to log them in...
            if (!$this->auth->attempt([$type => $login, 'password' => $password], $remember)) {
                return redirect()->back()->withInput()->with('error', $this->auth->error() ?? lang('Auth.badAttempt'));
            }
            // Is the user being forced to reset their password?
            if ($this->auth->user()->force_pass_reset === true) {
                return redirect()->to(route_to('reset-password') . '?token=' . $this->auth->user()->reset_hash)->withCookies();
            }

            $redirectURL = session('redirect_url') ?? site_url('/kb');
            unset($_SESSION['redirect_url']);
            return redirect()->to($redirectURL)->withCookies()->with('message', lang('Auth.loginSuccess'));
        } else {
            $credential = $this->request->getVar('credential');
            $userprofiles = $this->googleClient->verifyIdToken($credential);
            $userExists = $this->userDB->where('email', $userprofiles['email'])->first();
            if (!$userExists) {
                session()->set('userprofiles', $userprofiles);
                return redirect()->to('/kb/register');
            } else {
                $login    = $userExists->email;
                $password = $userprofiles['sub'];
                $remember = (bool) $this->request->getPost('remember');
                $type = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
                $redirectURL = session('redirect_url') ?? site_url('/kb');
                unset($_SESSION['redirect_url']);

                // Try to log them in...
                if (!$this->auth->attempt([$type => $login, 'password' => $password], $remember)) {
                    return redirect()->back()->withInput()->with('error', $this->auth->error() ?? lang('Auth.badAttempt'));
                }
                return redirect()->to($redirectURL)->withCookies()->with('message', lang('Auth.loginSuccess'));
            }
        }
    }

    /**
     * Log the user out.
     */
    public function logout()
    {
        if ($this->auth->check()) {
            $this->auth->logout();
        }

        return redirect()->to(site_url('/'));
    }

    //--------------------------------------------------------------------
    // Register
    //--------------------------------------------------------------------

    /**
     * Displays the user registration page.
     */
    public function register()
    {
        // Load your project data
        $project = $this->projectModel->findAll();
        if (session('userprofiles') === null) {
            // check if already logged in.
            if ($this->auth->check()) {
                return redirect()->back();
            }

            // Check if registration is allowed
            if (!$this->config->allowRegistration) {
                return redirect()->back()->withInput()->with('error', lang('Auth.registerDisabled'));
            }

            // Pass data common register to the view
            return $this->_render($this->config->views['register'], [
                'config' => $this->config,
                'title' => 'Virtusee | Register',
                'project' => $project
            ]);
        } else {
            // Pass data google register to the view
            return $this->_render($this->config->views['register'], [
                'config' => $this->config,
                'title' => 'Virtusee | Register',
                'project' => $project,
                'user' => session('userprofiles')
            ]);
        }
    }

    /**
     * Attempt to register a new user.
     */
    public function attemptRegister()
    {
        // Check if registration is allowed
        if (!$this->config->allowRegistration) {
            return redirect()->back()->withInput()->with('error', lang('Auth.registerDisabled'));
        }

        $users = model(UserModel::class);

        if (session('userprofiles') === null) {
            // Validate basics first since some password rules rely on these fields
            $rules = config('Validation')->registrationRules ?? [
                'username' => 'required|alpha_numeric_space|min_length[3]|max_length[30]|is_unique[users.username]',
                'email'    => 'required|valid_email|is_unique[users.email]',
            ];


            if (!$this->validate($rules)) {
                return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
            }

            // Validate passwords since they can only be validated properly here
            $rules = [
                'password'     => 'required|strong_password',
                'pass_confirm' => 'required|matches[password]',
            ];

            if (!$this->validate($rules)) {
                return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
            }

            // Set id_project to "0" for new users
            $idProject = $this->request->getPost('status_user') === 'new_user' ? '0' : $this->request->getPost('id_project');


            // Save the user
            $allowedPostFields = array_merge(['password'], $this->config->validFields, $this->config->personalFields);
            $user              = new User(array_merge($this->request->getPost($allowedPostFields), ['id_project' => $idProject]));

            $this->config->requireActivation === null ? $user->activate() : $user->generateActivateHash();

            // Ensure default group gets assigned if set
            if (!empty($this->config->defaultUserGroup)) {
                $users = $users->withGroup($this->config->defaultUserGroup);
            }

            if (!$users->save($user)) {
                return redirect()->back()->withInput()->with('errors', $users->errors());
            }

            if ($this->config->requireActivation !== null) {
                $activator = service('activator');
                $sent      = $activator->send($user);

                if (!$sent) {
                    return redirect()->back()->withInput()->with('error', $activator->error() ?? lang('Auth.unknownError'));
                }

                // Success!
                return redirect()->route('login')->with('message', lang('Auth.activationSuccess'));
            }

            // Success!
            return redirect()->route('login')->with('message', lang('Auth.registerSuccess'));
        } else {
            // Set id_project to "0" for new users
            $rules = [
                'status_user' => 'required',
            ];
            if (!$this->validate($rules)) {
                return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
            }
            $idProject = $this->request->getPost('status_user') === 'new_user' ? '0' : $this->request->getPost('id_project');
            // Get all form data using the request object

            $formData = $this->request->getPost();

            // Now you have all form data in the $formData array

            // You can access specific fields like this:
            $email = $formData['email'];
            $username = strstr($email, '@', true);
            $password = session('userprofiles')['sub'];

            $allowedPostFields = array_merge(['password'], $this->config->validFields, $this->config->personalFields);
            $user = new User(array_merge($this->request->getPost($allowedPostFields), ['id_project' => $idProject, 'active' => '1', 'username' => $username, 'password' => $password]));

            // Ensure default group gets assigned if set
            if (!empty($this->config->defaultUserGroup)) {
                $users = $users->withGroup($this->config->defaultUserGroup);
            }

            if (!$users->save($user)) {
                return redirect()->back()->withInput()->with('errors', $users->errors());
            }
            $login    = $email;
            $remember = (bool) $this->request->getPost('remember');
            $type = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

            $redirectURL = session('redirect_url') ?? site_url('/kb');
            unset($_SESSION['redirect_url']);


            // Try to log them in...
            if (!$this->auth->attempt([$type => $login, 'password' => $password], $remember)) {
                return redirect()->back()->withInput()->with('error', $this->auth->error() ?? lang('Auth.badAttempt'));
            }

            return redirect()->to($redirectURL)->withCookies()->with('message', lang('Auth.loginSuccess'));
        }
    }

    //--------------------------------------------------------------------
    // Forgot Password
    //--------------------------------------------------------------------

    /**
     * Displays the forgot password form.
     */
    public function forgotPassword()
    {
        if ($this->config->activeResetter === null) {
            return redirect()->route('login')->with('error', lang('Auth.forgotDisabled'));
        }

        return $this->_render($this->config->views['forgot'], ['config' => $this->config, 'title' => 'Virtusee | Forgot Password']);
    }

    /**
     * Attempts to find a user account with that password
     * and send password reset instructions to them.
     */
    public function attemptForgot()
    {
        if ($this->config->activeResetter === null) {
            return redirect()->route('login')->with('error', lang('Auth.forgotDisabled'));
        }

        $rules = [
            'email' => [
                'label' => lang('Auth.emailAddress'),
                'rules' => 'required|valid_email',
            ],
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $users = model(UserModel::class);

        $user = $users->where('email', $this->request->getPost('email'))->first();

        if (null === $user) {
            return redirect()->back()->with('error', lang('Auth.forgotNoUser'));
        }

        // Save the reset hash /
        $user->generateResetHash();
        $users->save($user);

        $resetter = service('resetter');
        $sent     = $resetter->send($user);

        if (!$sent) {
            return redirect()->back()->withInput()->with('error', $resetter->error() ?? lang('Auth.unknownError'));
        }

        return redirect()->route('reset-password')->with('message', lang('Auth.forgotEmailSent'));
    }

    /**
     * Displays the Reset Password form.
     */
    public function resetPassword()
    {
        if ($this->config->activeResetter === null) {
            return redirect()->route('login')->with('error', lang('Auth.forgotDisabled'));
        }

        $token = $this->request->getGet('token');

        return $this->_render($this->config->views['reset'], [
            'config' => $this->config,
            'token'  => $token,
            'title' => 'Virtusee | Reset Password'
        ]);
    }

    /**
     * Verifies the code with the email and saves the new password,
     * if they all pass validation.
     *
     * @return mixed
     */
    public function attemptReset()
    {
        if ($this->config->activeResetter === null) {
            return redirect()->route('login')->with('error', lang('Auth.forgotDisabled'));
        }

        $users = model(UserModel::class);

        // First things first - log the reset attempt.
        $users->logResetAttempt(
            $this->request->getPost('email'),
            $this->request->getPost('token'),
            $this->request->getIPAddress(),
            (string) $this->request->getUserAgent()
        );

        $rules = [
            'token'        => 'required',
            'email'        => 'required|valid_email',
            'password'     => 'required|strong_password',
            'pass_confirm' => 'required|matches[password]',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $user = $users->where('email', $this->request->getPost('email'))
            ->where('reset_hash', $this->request->getPost('token'))
            ->first();

        if (null === $user) {
            return redirect()->back()->with('error', lang('Auth.forgotNoUser'));
        }

        // Reset token still valid?
        if (!empty($user->reset_expires) && time() > $user->reset_expires->getTimestamp()) {
            return redirect()->back()->withInput()->with('error', lang('Auth.resetTokenExpired'));
        }

        // Success! Save the new password, and cleanup the reset hash.
        $user->password         = $this->request->getPost('password');
        $user->reset_hash       = null;
        $user->reset_at         = date('Y-m-d H:i:s');
        $user->reset_expires    = null;
        $user->force_pass_reset = false;
        $users->save($user);

        return redirect()->route('login')->with('message', lang('Auth.resetSuccess'));
    }

    /**
     * Activate account.
     *
     * @return mixed
     */
    public function activateAccount()
    {
        $users = model(UserModel::class);

        // First things first - log the activation attempt.
        $users->logActivationAttempt(
            $this->request->getGet('token'),
            $this->request->getIPAddress(),
            (string) $this->request->getUserAgent()
        );

        $throttler = service('throttler');

        if ($throttler->check(md5($this->request->getIPAddress()), 2, MINUTE) === false) {
            return service('response')->setStatusCode(429)->setBody(lang('Auth.tooManyRequests', [$throttler->getTokentime()]));
        }

        $user = $users->where('activate_hash', $this->request->getGet('token'))
            ->where('active', 0)
            ->first();

        if (null === $user) {
            return redirect()->route('login')->with('error', lang('Auth.activationNoUser'));
        }

        $user->activate();

        $users->save($user);

        return redirect()->route('login')->with('message', lang('Auth.registerSuccess'));
    }

    /**
     * Resend activation account.
     *
     * @return mixed
     */
    public function resendActivateAccount()
    {
        if ($this->config->requireActivation === null) {
            return redirect()->route('login');
        }

        $throttler = service('throttler');

        if ($throttler->check(md5($this->request->getIPAddress()), 2, MINUTE) === false) {
            return service('response')->setStatusCode(429)->setBody(lang('Auth.tooManyRequests', [$throttler->getTokentime()]));
        }

        $login = urldecode($this->request->getGet('login'));
        $type  = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        $users = model(UserModel::class);

        $user = $users->where($type, $login)
            ->where('active', 0)
            ->first();

        if (null === $user) {
            return redirect()->route('login')->with('error', lang('Auth.activationNoUser'));
        }

        $activator = service('activator');
        $sent      = $activator->send($user);

        if (!$sent) {
            return redirect()->back()->withInput()->with('error', $activator->error() ?? lang('Auth.unknownError'));
        }

        // Success!
        return redirect()->route('login')->with('message', lang('Auth.activationSuccess'));
    }

    protected function _render(string $view, array $data = [])
    {
        return view($view, $data);
    }
}
