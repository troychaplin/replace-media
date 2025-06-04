# Replace Media Plugin

A WordPress plugin that allows you to replace media files while maintaining their original URLs and metadata. This is particularly useful for updating files like PDFs, images, or other documents without breaking existing links.

## Features

- Replace media files while maintaining original URLs
- Preserves all existing links (both internal and external)
- Maintains file metadata and relationships
- Simple and intuitive interface in the WordPress Media Library
- Supports all file types supported by WordPress
- Validates file names to prevent accidental URL changes

## Installation

1. Download the plugin files
2. Upload the `replace-media` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

1. Go to the WordPress Media Library
2. Find the file you want to replace
3. Click the "Replace File" button
4. Select your new file
5. Important: The new file must have the same name as the original file
6. Click "Upload" to replace the file

### Important Notes

- The new file must have exactly the same filename as the original file
- If the filenames don't match, you'll receive an error message with the required filename
- All existing links to the file will continue to work after replacement
- The file's metadata (title, caption, etc.) will be preserved

## Development

### Building from Source

1. Clone the repository
2. Install dependencies:
    ```bash
    npm install
    ```
3. Build the JavaScript files:
    ```bash
    npm run build
    ```

### File Structure

- `Functions/` - PHP functions and WordPress hooks
- `src/` - JavaScript source files
- `build/` - Compiled JavaScript files

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher

## Support

For support, please open an issue on the GitHub repository.

## License

This plugin is licensed under the GPL v2 or later.
