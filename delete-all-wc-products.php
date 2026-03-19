<?php
/**
 * Plugin Name: Delete All WooCommerce Products
 * Description: Adds a button to permanently delete all WooCommerce products (including variations) in one click.
 * Version: 1.0
 * Author: Alexander Nemirov
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Add submenu under Products
add_action( 'admin_menu', 'dawp_add_admin_menu' );
function dawp_add_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=product',
        'Delete All Products',
        'Delete All Products',
        'manage_options',
        'delete-all-products',
        'dawp_render_admin_page'
    );
}

// Render admin page
function dawp_render_admin_page() {
    ?>
    <div class="wrap">
        <h1>Delete All WooCommerce Products</h1>
        <p style="color: red; font-weight: bold; font-size: 1.2em;">
            WARNING: This action is irreversible! All products and variations will be permanently deleted (bypassing trash).
        </p>
        <p>It is recommended to back up your database before proceeding.</p>

        <form method="post" onsubmit="return confirm('Are you SURE you want to delete ALL products? This cannot be undone.');">
            <?php wp_nonce_field( 'dawp_delete_action', 'dawp_nonce' ); ?>
            <input type="submit" name="dawp_delete_all" class="button button-primary" value="DELETE ALL PRODUCTS PERMANENTLY">
        </form>

        <?php
        if ( isset( $_POST['dawp_delete_all'] ) && check_admin_referer( 'dawp_delete_action', 'dawp_nonce' ) ) {
            dawp_execute_deletion();
        }
        ?>
    </div>
    <?php
}

// Deletion logic
function dawp_execute_deletion() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Get all product and variation IDs
    $product_ids = get_posts( array(
        'post_type'   => array( 'product', 'product_variation' ),
        'numberposts' => -1,
        'post_status' => 'any',
        'fields'      => 'ids',
    ) );

    if ( empty( $product_ids ) ) {
        echo '<div class="updated"><p>No products found.</p></div>';
        return;
    }

    $count = 0;
    foreach ( $product_ids as $id ) {
        // true = permanent delete, bypass trash
        if ( wp_delete_post( $id, true ) ) {
            $count++;
        }
    }

    // Clean up orphaned metadata and transients
    wc_delete_product_transients();

    echo '<div class="updated"><p>Successfully deleted ' . $count . ' items. Store is empty.</p></div>';
}
