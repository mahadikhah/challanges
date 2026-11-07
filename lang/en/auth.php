<?php

return [

    /*
    | Auth-page copy for the starter-kit-derived Fortify surfaces. Group keys
    | mirror the page file names in resources/js/pages/auth/.
    */

    'login' => [
        'title' => 'Log in to your account',
        'description' => 'Enter your email and password below to log in',
        'head' => 'Log in',
        'email' => 'Email address',
        'password' => 'Password',
        'forgot' => 'Forgot your password?',
        'remember' => 'Remember me',
        'submit' => 'Log in',
        'no_account' => "Don't have an account?",
        'signup' => 'Sign up',
    ],

    'register' => [
        'title' => 'Create an account',
        'description' => 'Enter your details below to create your account',
        'head' => 'Register',
        'name' => 'Name',
        'name_placeholder' => 'Full name',
        'email' => 'Email address',
        'password' => 'Password',
        'confirm_password' => 'Confirm password',
        'confirm_password_placeholder' => 'Confirm password',
        'submit' => 'Create account',
        'has_account' => 'Already have an account?',
        'login' => 'Log in',
    ],

    'forgot' => [
        'title' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'head' => 'Forgot password',
        'email' => 'Email address',
        'submit' => 'Email password reset link',
        'or_return' => 'Or, return to',
        'login' => 'log in',
    ],

    'reset' => [
        'title' => 'Reset password',
        'description' => 'Please enter your new password below',
        'head' => 'Reset password',
        'email' => 'Email',
        'password' => 'Password',
        'confirm_password' => 'Confirm password',
        'submit' => 'Reset password',
    ],

    'confirm' => [
        'title' => 'Confirm password',
        'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
        'head' => 'Confirm password',
        'password' => 'Password',
        'submit' => 'Confirm password',
    ],

    'verify' => [
        'title' => 'Email verification',
        'description' => 'Please verify your email address by clicking on the link we just emailed to you.',
        'head' => 'Email verification',
        'sent' => 'A new verification link has been sent to the email address you provided during registration.',
        'resend' => 'Resend verification email',
        'logout' => 'Log out',
    ],

    /*
    | Shared field copy used across auth and settings forms.
    */

    'fields' => [
        'email_placeholder' => 'email@example.com',
        'password_placeholder' => 'Password',
    ],

];
