<?php
/**
 * Plugin Name: Delete All WooCommerce Products
 * Plugin URI:  https://github.com/anemirov/delete-all-wc-products
 * Description: Adds a button to permanently delete all WooCommerce products (including variations) in one click.
 * Version:     1.0
 * Author:      Alexander Nemirov
 * Author URI:  https://github.com/anemirov
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: delete-all-wc-products
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * Плагин добавляет кнопку для перманентного удаления всех товаров WooCommerce
 * (включая вариации) одним нажатием. Используется для полной очистки каталога
 * перед импортом, миграцией или тестированием.
 *
 * @package DeleteAllWCProducts
 */

// Защита от прямого доступа к файлу
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Регистрирует подменю плагина в разделе «Товары» (Products).
 *
 * Добавляет пункт «Delete All Products» в подменю WooCommerce Products.
 * Доступ ограничен пользователями с правом manage_options (администраторы).
 *
 * @since 1.0
 *
 * @return void
 */
function dawp_add_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=product',  // Родительское меню — Товары
        'Delete All Products',          // Заголовок страницы
        'Delete All Products',          // Название пункта меню
        'manage_options',               // Требуемое право доступа
        'delete-all-products',          // Уникальный slug страницы
        'dawp_render_admin_page'        // Callback-функция рендера страницы
    );
}
add_action( 'admin_menu', 'dawp_add_admin_menu' );

/**
 * Отображает страницу администрирования плагина.
 *
 * Выводит предупреждение, форму с кнопкой удаления и nonce-поле для защиты.
 * При отправке формы проверяет nonce и запускает процесс удаления.
 *
 * @since 1.0
 *
 * @return void
 */
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
        // Обработка отправки формы: проверяем nonce и запускаем удаление
        if ( isset( $_POST['dawp_delete_all'] ) && check_admin_referer( 'dawp_delete_action', 'dawp_nonce' ) ) {
            dawp_execute_deletion();
        }
        ?>
    </div>
    <?php
}

/**
 * Выполняет перманентное удаление всех товаров и вариаций WooCommerce.
 *
 * Получает все записи типов 'product' и 'product_variation' с любым статусом,
 * удаляет их без перемещения в корзину (force delete), а затем очищает
 * транзиенты WooCommerce для корректного обновления кешей.
 *
 * @since 1.0
 *
 * @return void Выводит сообщение с результатом операции.
 */
function dawp_execute_deletion() {
    // Проверка прав: только администратор может удалять товары
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Получаем ID всех товаров и вариаций независимо от статуса
    $product_ids = get_posts( array(
        'post_type'   => array( 'product', 'product_variation' ),
        'numberposts' => -1,         // Без ограничения количества
        'post_status' => 'any',      // Любой статус (published, draft, и т.д.)
        'fields'      => 'ids',      // Возвращаем только ID для экономии памяти
    ) );

    // Если товаров нет — выводим уведомление и выходим
    if ( empty( $product_ids ) ) {
        echo '<div class="updated"><p>No products found.</p></div>';
        return;
    }

    // Счётчик успешно удалённых записей
    $count = 0;
    foreach ( $product_ids as $id ) {
        // true = перманентное удаление, минуя корзину
        if ( wp_delete_post( $id, true ) ) {
            $count++;
        }
    }

    // Очистка транзиентов WooCommerce (кеш подсчётов, цен и т.д.)
    wc_delete_product_transients();

    echo '<div class="updated"><p>Successfully deleted ' . $count . ' items. Store is empty.</p></div>';
}
