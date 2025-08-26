# Made in China App Sync

A professional WordPress plugin that synchronizes WooCommerce paid orders with your Laravel ebook application.

## Features

- **Automatic Order Sync**: Automatically syncs WooCommerce orders to your Laravel app when payment is completed
- **Professional Admin Interface**: Clean, modern admin interface with analytics dashboard
- **Multilingual Support**: Full support for English and French languages
- **Comprehensive Logging**: Detailed sync logs with filtering and management
- **Analytics Dashboard**: Visual charts and performance metrics
- **Security**: Webhook signature verification for secure communication
- **Manual Sync**: Ability to manually sync orders when needed

## Installation

1. Upload the plugin files to `/wp-content/plugins/made-in-china-app-sync/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin settings in the admin panel

## Configuration

1. Go to **MIC App Sync** in your WordPress admin menu
2. Enter your Laravel application URL (e.g., `https://app.madeinchina-ebook.com`)
3. Enter your webhook secret (configured in your Laravel app's `.env` file)
4. Test the connection to ensure everything is working
5. Make sure your WooCommerce products have SKUs that match your Laravel ebook identifiers

## Laravel App Setup

In your Laravel application, ensure you have:

1. **Environment Variables** in `.env`:
   ```
   WOOCOMMERCE_ENABLED=true
   WOOCOMMERCE_WEBHOOK_SECRET=your-secret-here
   ```

2. **Database Migration**:
   ```bash
   php artisan migrate
   ```

3. **API Route**:
   ```php
   Route::post('/api/v1/woocommerce-sync', [WooCommerceController::class, 'sync']);
   ```

## Plugin Structure

```
made-in-china-app-sync/
├── main.php                 # Main plugin file
├── assets/
│   ├── css/
│   │   └── admin.css        # Admin interface styles
│   └── js/
│       └── admin.js         # Admin interface JavaScript
├── languages/
│   ├── made-in-china-app-sync-fr_FR.po  # French translations
│   ├── made-in-china-app-sync-fr_FR.mo  # Compiled French translations
│   └── README.md            # Translation documentation
└── README.md               # This file
```

## Professional Features

### Clean Code Architecture
- **Separated Concerns**: CSS, JavaScript, and PHP are properly separated
- **Modular JavaScript**: Object-oriented JavaScript with proper event handling
- **Professional CSS**: Well-organized stylesheets with proper naming conventions
- **WordPress Standards**: Follows WordPress coding standards and best practices

### Asset Management
- **Proper Enqueuing**: Uses WordPress `wp_enqueue_style()` and `wp_enqueue_script()`
- **Conditional Loading**: Assets only load on relevant admin pages
- **Version Control**: Proper versioning for cache busting
- **CDN Integration**: Uses CDN for external libraries (Remix Icons, Chart.js)

### Internationalization
- **Full i18n Support**: All user-facing strings are translatable
- **French Language**: Complete French translation included
- **Extensible**: Easy to add more languages
- **JavaScript Localization**: JavaScript strings are properly localized

### User Experience
- **Modern UI**: Clean, professional interface with consistent styling
- **Responsive Design**: Works on all screen sizes
- **Interactive Elements**: Smooth animations and transitions
- **Visual Feedback**: Clear status indicators and loading states

## Admin Pages

### Dashboard
- Plugin configuration
- Connection testing
- Setup guide

### Sync Logs
- View all synchronization attempts
- Filter by status (Success, Failed, Pending)
- Clear old logs
- Detailed error information

### Analytics
- Visual charts showing sync performance
- Success rate metrics
- Daily activity graphs
- Performance statistics

## API Integration

The plugin sends HTTP POST requests to your Laravel app with the following payload:

```json
{
    "order_id": 123,
    "email": "customer@example.com",
    "name": "John Doe",
    "products": [
        {
            "sku": "ebook-001",
            "name": "My Awesome Ebook"
        }
    ]
}
```

## Security

- **Webhook Signatures**: All requests include HMAC-SHA256 signatures
- **Nonce Verification**: WordPress nonces for form submissions
- **Input Sanitization**: All user inputs are properly sanitized
- **Capability Checks**: Proper user permission verification

## Multilingual Support

The plugin supports multiple languages:

- **English** (default)
- **French** (fr_FR)

To add support for additional languages, see the documentation in `/languages/README.md`.

## Development

### Adding New Features

1. **CSS**: Add styles to `assets/css/admin.css`
2. **JavaScript**: Add functionality to `assets/js/admin.js`
3. **PHP**: Add functions to `main.php`
4. **Translations**: Update translation files for new strings

### Code Standards

- Follow WordPress coding standards
- Use proper indentation and formatting
- Comment complex functionality
- Use meaningful variable and function names

## Support

For support, feature requests, or bug reports, please contact the plugin author.

## Changelog

### Version 1.2.0
- Added multilingual support (English/French)
- Restructured code with separate CSS/JS files
- Improved admin interface design
- Added comprehensive analytics dashboard
- Enhanced security with proper asset enqueuing
- Professional code organization

### Version 1.1.0
- Added sync logs functionality
- Improved error handling
- Added manual sync capability

### Version 1.0.0
- Initial release
- Basic order synchronization
- Admin configuration interface

## License

This plugin is licensed under the GPL v2 or later.

## Credits

- **Icons**: Remix Icons (https://remixicon.com/)
- **Charts**: Chart.js (https://www.chartjs.org/)
- **Framework**: WordPress (https://wordpress.org/)
