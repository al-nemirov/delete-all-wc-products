# Delete All WooCommerce Products

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue?logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-Required-96588A?logo=woocommerce&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

[English](#english) | [Русский](#русский)

---

## English

One-click deletion of all WooCommerce products and variations.

### Use Case

Complete catalog cleanup before import, migration, or testing. Permanently deletes all products (bypasses trash) and clears WooCommerce transients.

### Features

- Delete all products and variations with one click
- Permanent deletion (bypass trash)
- WooCommerce transient cleanup after deletion
- Confirmation dialog before execution
- Security: nonce verification + `manage_options` capability check

### Installation

#### Manual Installation

1. Download or clone this repository:
   ```bash
   git clone https://github.com/anemirov/delete-all-wc-products.git
   ```
2. Copy the `delete-all-wc-products` folder to `wp-content/plugins/`
3. Activate the plugin in **Plugins** menu
4. Go to **Products -> Delete All Products**

#### Via WordPress Admin

1. Download the plugin as a ZIP archive
2. Go to **Plugins -> Add New -> Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Activate the plugin
5. Go to **Products -> Delete All Products**

### Security

- The plugin checks for `manage_options` capability (admin only)
- WordPress nonce is used to prevent CSRF attacks
- A JavaScript confirmation dialog prevents accidental clicks

> **WARNING:** This action is **irreversible**. All products and variations will be permanently deleted, bypassing the trash. **Always back up your database before using this plugin.**

---

## Русский

Удаление всех товаров WooCommerce (включая вариации) одной кнопкой.

### Для чего

Полная очистка каталога перед импортом, миграцией или тестированием.

### Возможности

- Удаление всех товаров и вариаций одним нажатием
- Перманентное удаление (минуя корзину)
- Очистка транзиентов WooCommerce
- Диалог подтверждения перед выполнением
- Защита: проверка nonce + проверка права `manage_options`

### Установка

#### Ручная установка

1. Скачайте или клонируйте репозиторий:
   ```bash
   git clone https://github.com/anemirov/delete-all-wc-products.git
   ```
2. Скопируйте папку `delete-all-wc-products` в `wp-content/plugins/`
3. Активируйте плагин в меню **Плагины**
4. Перейдите в **Товары -> Delete All Products**

#### Через админку WordPress

1. Скачайте плагин как ZIP-архив
2. Перейдите в **Плагины -> Добавить новый -> Загрузить плагин**
3. Загрузите ZIP-файл и нажмите **Установить**
4. Активируйте плагин
5. Перейдите в **Товары -> Delete All Products**

### Безопасность

- Плагин проверяет право `manage_options` (только администратор)
- Используется WordPress nonce для защиты от CSRF-атак
- JavaScript-диалог подтверждения предотвращает случайные нажатия

> **ВНИМАНИЕ:** Действие **необратимо**. Все товары и вариации будут удалены навсегда, минуя корзину. **Обязательно сделайте резервную копию базы данных перед использованием.**

---

## Contributing / Участие в разработке

Contributions are welcome! Feel free to open issues or submit pull requests.

Будем рады вашему участию! Создавайте issues или отправляйте pull requests.

1. Fork the repository / Сделайте форк репозитория
2. Create a feature branch / Создайте ветку для новой функции (`git checkout -b feature/my-feature`)
3. Commit your changes / Зафиксируйте изменения (`git commit -m 'Add my feature'`)
4. Push to the branch / Отправьте в ветку (`git push origin feature/my-feature`)
5. Open a Pull Request / Откройте Pull Request

---

## Author / Автор

Alexander Nemirov

## License / Лицензия

This project is licensed under the [MIT License](LICENSE).
