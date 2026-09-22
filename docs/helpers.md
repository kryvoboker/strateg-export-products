[← Configuration](configuration.md) · [Back to README](../README.md) · [Supports Reference →](supports.md)

# Helper Functions

The file app-code/app/app/Supports/helpers.php is loaded through autoload.files in composer.json. These functions are available throughout the Laravel application after the Composer autoloader is loaded.

## Phones and Strings

| Function | Purpose |
|---|---|
| clear_telephone | Keeps digits and optionally removes the 38 prefix |
| parse_telephone | Formats a phone with the +38 (___) ___-__-__ mask |
| trim_strs_in_arr | Trims string values in an array |
| normalize_str | Decodes HTML entities, trims the string, and replaces NBSP |
| sanitize_str | Removes HTML and collapses whitespace |
| normalize_trimmed_str | Normalizes a string or number, or returns an empty string |
| normalize_nullable_trimmed_str | Returns a normalized string or null |
| nullable_string | Converts a scalar to a trimmed string or null |
| resolve_string | Returns a string or the fallback |

## HTML and URLs

| Function | Purpose |
|---|---|
| decode_html_entities | Decodes HTML entities as UTF-8 |
| escape_special_html | Decodes HTML and escapes script blocks and apostrophes |
| validate_url | Validates an absolute URL with FILTER_VALIDATE_URL |
| resolve_public_url | Allows http://, https://, and relative paths |
| resolve_upload_path_placeholders | Replaces year and month placeholders with the current date |

## Values and Arrays

| Function | Purpose |
|---|---|
| normalize_positive_int_list | Keeps unique positive integers |
| normalize_array_payload | Returns an array or [] |
| string_value | Returns a scalar as a string or an empty string |
| integer_value | Returns an integer or 0 |
| array_value | Returns an array or [] |
| string_keyed_array | Returns an array with string keys or [] |
| boolean_value | Converts a value to boolean |
| float_value | Returns a float or 0.0 |
| list_value | Returns an array with reset keys |

## Telegram

| Function | Purpose |
|---|---|
| get_telegram_message | Returns the regular or edited message |
| get_telegram_photo | Returns message photos |
| get_telegram_doc | Returns the message document |
| is_telegram_has_photo | Checks whether a photo exists |
| is_telegram_has_doc | Checks whether a document exists |

## Dates, JSON, and Locales

| Function | Purpose |
|---|---|
| get_now_date | Returns the current date in the supplied or application timezone |
| to_json | Encodes JSON and returns an empty string on failure |
| from_json | Decodes JSON into an array or object |
| json_encode_throw | Encodes JSON with JSON_THROW_ON_ERROR |
| json_decode_throw | Decodes JSON with JSON_THROW_ON_ERROR |
| get_allowed_locales | Returns locales from app.allowed_locales |
| log_stack_trace | Builds lines for the current PHP stack trace |

## Example

    $telephone = clear_telephone($input['telephone'] ?? null);
    $payload = normalize_array_payload($input['payload'] ?? null);
    $json = json_encode_throw($payload);

## See Also

- [Configuration](configuration.md) — application and store settings.
- [Product Import](product-import-batch-resource.md) — normalization in import flows.
- [Architecture](architecture.md) — the place of helpers in the application.
