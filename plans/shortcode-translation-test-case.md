# Shortcode Translation Test Case for ru.pbservices.ge

## Objective
Investigate why shortcodes with `[frl]english_string[/frl]` are not translating on the ru.pbservices.ge subdomain while block translations work correctly.

## Test Scenario Setup
1. Environment:
   - Domain: ru.pbservices.ge
   - Default Language: ru
   - Source Language: en (constant)

2. Test Shortcode Variations
```php
// Test Case 1: Basic Translation
[frl]Hello World[/frl]

// Test Case 2: With HTML
[frl]<strong>Important Message</strong>[/frl]

// Test Case 3: Nested Shortcodes
[frl]Welcome to [frl_lang lang="en"]English Version[/frl_lang][/frl]

// Test Case 4: Complex Content
[frl]Registration for the event starts at {{event_time}} on [frl_year][/frl]
```

## Debugging Checklist
- [ ] Verify current language detection
- [ ] Check translation service configuration
- [ ] Inspect shortcode processing hooks
- [ ] Validate translation adapter behavior

## Expected Behavior
- Shortcodes should translate to Russian when on ru.pbservices.ge
- Fallback to original text if no translation exists
- Consistent with block translation mechanism

## Potential Failure Points
1. Language detection mismatch
2. Missing translation registration
3. Shortcode-specific translation logic
4. Caching interference

## Diagnostic Information to Collect
- Current language
- Source language
- Translation adapter state
- Shortcode processing logs
- Cache contents for translations

## Recommended Debugging Steps
1. Add logging to `frl_get_translation()` function
2. Verify language detection in translation service
3. Check if shortcode translation is being skipped
4. Validate Polylang adapter behavior
5. Inspect caching mechanism for translations