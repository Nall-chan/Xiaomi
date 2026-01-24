# IP-Symcon Xiaomi MIoT Module Development Guide

This guide helps AI agents understand and work effectively with the IP-Symcon Xiaomi MIoT module codebase.

## Project Architecture

This is a PHP module for IP-Symcon home automation software that enables integration with Xiaomi MIoT devices. The project consists of three main components:

1. **Cloud IO Module** (`Xiaomi MIoT Cloud IO/`) - Handles cloud connectivity and authentication
2. **Device Module** (`Xiaomi MIoT Device/`) - Manages individual device interactions
3. **Configurator Module** (`Xiaomi MIoT Configurator/`) - Provides device discovery and setup

### Key Design Patterns

- **Trait Usage**: Core functionality is implemented as traits in `libs/helper/` (e.g., `DebugHelper.php`, `BufferHelper.php`)
- **Namespace Structure**: Uses `Xiaomi\` namespace with sub-namespaces for components (Cloud, Device, Configurator)
- **Constants Organization**: Global constants defined in `libs/XiaomiConsts.php`

## Development Workflow

### Code Style

The project uses PHP-CS-Fixer for code style enforcement. Two tasks are available:
- `CS-Fixer (check)` - Validates code style
- `CS-Fixer (fix)` - Automatically fixes code style issues

### JSON Validation

JSON files must be validated using:
- `JSON-Fixer (check)` - Validates JSON syntax
- `JSON-Fixer (fix)` - Fixes JSON formatting issues

### Testing

- PHPUnit tests located in `tests/`
- Run tests via GitHub Actions workflows:
  - Check Style
  - Run Tests 

## Common Development Tasks

### Adding New Device Support

1. Add device specifications to appropriate namespace in `libs/XiaomiConsts.php`
2. Create necessary variable profiles in `VariableProfileHelper.php`
3. Implement device-specific logic in `Xiaomi MIoT Device/module.php`

### Error Handling

- Use `SendDebug()` for logging (from `DebugHelper` trait)
- HTTP/API errors are mapped in `$CURL_error_codes` and `$http_error` arrays
- Status codes documented in module's `form.json`

## Key Files Reference

- `library.json` - Module metadata and version info
- `libs/XiaomiConsts.php` - Core constants and enums
- `libs/helper/*.php` - Reusable trait implementations
- `*/form.json` - Module configuration definitions
- `*/locale.json` - Translations for UI elements

## Device Communication Architecture

### Communication Flow

1. **Initial Connection**
   - Device handshake via UDP port 54321
   - Token-based authentication using AES-128-CBC encryption
   - Automatic failover between local and cloud communication

2. **Cloud Authentication**
   - Handles login through Xiaomi Cloud service
   - Multi-step authentication flow:
     1. Get login signature from cloud API
     2. Perform initial login with username/password
     3. Handle CAPTCHA or 2FA if required via `notificationUrl`
     4. Extract security tokens (`ssecurity`, `userId`, `location`)
     5. Generate service token for subsequent requests
   - **2FA Support**: When `securityStatus` is 16, handles verification internally by calling `notificationUrl` within Symcon
   - **CAPTCHA Support**: Handles CAPTCHA challenges during login process
   - Token management and refresh mechanism
   - Supports multiple regions via country code (default: 'de')

3. **Communication Modes**
   - **Local Mode**: Direct UDP communication with devices
   - **Cloud Mode**: HTTP requests through Xiaomi Cloud API
     - Uses RC4 encryption for data
     - Requires `serviceToken` and `ssecurity` for requests
     - Handles API cookies and headers automatically
   - Automatic failback to cloud if local communication fails

4. **Message Patterns**
   ```php
   // Local device request format
   [
     'id' => random_int(1, 65535),
     'method' => $Method,        // e.g. get_properties, set_properties
     'params' => $Params        // Device-specific parameters
   ]
   ```

### Protocol Security

- Encrypted communication using device token
- MD5-based message checksums for integrity
- Timestamp-based message validation

### Error Handling

- Connection retry mechanism with exponential backoff
- Error code mapping in `$CURL_error_codes` and `$http_error`
- Detailed debug logging via `SendDebug()` trait

## Integration Points

- Implements IP-Symcon module interface (Create, Destroy, ApplyChanges)
- Uses parent/child module architecture for device communication
- Supports multi-language via locale files (German/English)