<?php

return [

    /*
    | The starter-kit's account settings section: the tabbed layout shell,
    | the profile/security/appearance pages and the delete-account flow.
    */

    'title' => 'Settings',
    'description' => 'Manage your profile and account settings',

    'tabs' => [
        'profile' => 'Profile',
        'security' => 'Security',
        'appearance' => 'Appearance',
    ],

    'profile' => [
        'head' => 'Profile settings',
        'title' => 'Profile',
        'description' => 'Update your name and email address',
        'name' => 'Name',
        'name_placeholder' => 'Full name',
        'email' => 'Email address',
        'email_placeholder' => 'Email address',
        'unverified' => 'Your email address is unverified.',
        'resend' => 'Click here to re-send the verification email.',
        'resent' => 'A new verification link has been sent to your email address.',
    ],

    'security' => [
        'head' => 'Security settings',
        'title' => 'Update password',
        'description' => 'Ensure your account is using a long, random password to stay secure',
        'current_password' => 'Current password',
        'current_password_placeholder' => 'Current password',
        'new_password' => 'New password',
        'new_password_placeholder' => 'New password',
        'confirm_password' => 'Confirm password',
        'confirm_password_placeholder' => 'Confirm password',
    ],

    'appearance' => [
        'head' => 'Appearance settings',
        'title' => 'Appearance settings',
        'description' => 'Update the appearance settings for your account',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],

    'delete_user' => [
        'title' => 'Delete account',
        'description' => 'Delete your account and all of its resources',
        'warning' => 'Warning',
        'warning_body' => 'Please proceed with caution, this cannot be undone.',
        'delete' => 'Delete account',
        'dialog_title' => 'Are you sure you want to delete your account?',
        'dialog_body' => 'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
        'password' => 'Password',
    ],

];
