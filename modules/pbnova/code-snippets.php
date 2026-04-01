<?php

/**
 * PB Nova module constants
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}


add_action('my_full_conditional_registration', 'my_custom_registration_and_enrollment', 10, 2);

function frl_pbnova_registration_and_enrollment($form, $submit) {
    // 1. Extract the data you need (Email, Username, Password, Choice)
    $data = $submit->get_data();

    $email = $data['field_100']; // Replace with your Email field ID
    $username = $data['field_101']; // Replace with your Username field ID
    $password = $data['field_102']; // Replace with your Password field ID
    $account_type = $data['field_123']; // The 'Basic/Premium/Business' choice

    // Check if the user already exists (Crucial step for registration)
    if (username_exists($username) || email_exists($email)) {
        // Handle error: User already exists. (e.g., log it or return)
        return;
    }

    // 2. Create the WordPress User (Using the native WP function)
    $user_id = wp_insert_user(array(
        'user_login' => $username,
        'user_pass'  => $password,
        'user_email' => $email,
        'first_name' => $data['field_103'], // Example First Name field
        // Note: The new user will be assigned the default WordPress role ('subscriber') initially
    ));

    // Check for errors during user creation
    if (is_wp_error($user_id)) {
        // Handle error: User creation failed.
        return;
    }

    // 3. Assign the Conditional MemberPress Membership (The original custom code)
    $membership_id_to_assign = 0;
    if ($account_type === 'Basic') {
        $membership_id_to_assign = 12;
    } elseif ($account_type === 'Premium') {
        $membership_id_to_assign = 13;
    } // ... and so on

    // 4. Enroll the User in MemberPress
    if ($membership_id_to_assign > 0 && class_exists('MeprUser')) {
        $user = new MeprUser($user_id);

        $txn = new MeprTransaction();
        $txn->user_id = $user->ID;
        $txn->product_id = $membership_id_to_assign;
        $txn->status = MeprTransaction::$complete_str;
        $txn->store();

        $user->recalc_active_memberships();
    }
}
