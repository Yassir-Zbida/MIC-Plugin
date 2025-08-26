# Multilingual Support for Made in China App Sync

This plugin now supports multiple languages through WordPress's internationalization (i18n) system.

## Supported Languages

- **English** (default)
- **French** (fr_FR)

## How to Use

### For Users

1. **Change WordPress Language:**
   - Go to WordPress Admin → Settings → General
   - Change the "Site Language" to your preferred language
   - The plugin interface will automatically display in the selected language

2. **For French Users:**
   - Set WordPress language to "Français" (French)
   - All plugin text will display in French

### For Developers

#### Adding New Languages

1. **Create Translation File:**
   ```bash
   # Create a new .po file for your language
   cp languages/made-in-china-app-sync-fr_FR.po languages/made-in-china-app-sync-[LANG_CODE].po
   ```

2. **Translate Strings:**
   - Edit the `.po` file
   - Translate all `msgstr` values to your target language
   - Update the header information (Language, Language-Team, etc.)

3. **Compile Translation:**
   ```bash
   # Compile the .po file to .mo file
   msgfmt languages/made-in-china-app-sync-[LANG_CODE].po -o languages/made-in-china-app-sync-[LANG_CODE].mo
   ```

#### Adding New Translatable Strings

When adding new user-facing text to the plugin:

1. **Wrap strings with translation functions:**
   ```php
   // For echo output
   echo __( 'Your text here', 'made-in-china-app-sync' );
   
   // For direct output
   _e( 'Your text here', 'made-in-china-app-sync' );
   
   // For JavaScript
   echo esc_js( __( 'Your text here', 'made-in-china-app-sync' ) );
   ```

2. **Update translation files:**
   - Add the new string to all `.po` files
   - Compile the `.mo` files

## File Structure

```
languages/
├── made-in-china-app-sync-fr_FR.po    # French translation source
├── made-in-china-app-sync-fr_FR.mo    # French translation compiled
└── README.md                          # This file
```

## Technical Details

- **Text Domain:** `made-in-china-app-sync`
- **Domain Path:** `/languages`
- **WordPress Hook:** `plugins_loaded` (loads translations early)
- **Translation Functions Used:**
  - `__()` - Returns translated string
  - `_e()` - Echoes translated string
  - `esc_js()` - Escapes strings for JavaScript
  - `_n()` - Handles plural forms

## Language Codes

- English: `en_US` (default, no translation file needed)
- French: `fr_FR`
- Spanish: `es_ES`
- German: `de_DE`
- Italian: `it_IT`

## Contributing Translations

If you'd like to contribute translations for other languages:

1. Fork the repository
2. Create translation files for your language
3. Test the translations thoroughly
4. Submit a pull request

## Support

For translation issues or to request support for additional languages, please contact the plugin author.
