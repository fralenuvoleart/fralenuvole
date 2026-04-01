# FRL Rewriter System - Independent Feature Architecture

## 🎯 Independence Strategy

**Goal**: ANY change to the code of one feature does not change in ANY way the working behaviour of another feature.

### Independence Implementation:
- **Self-Registration**: Features register themselves via WordPress hooks (no central dependency list)
- **Isolated Configuration**: Each feature reads only its own configuration options
- **Separate Rule Generation**: Features generate their own rewrite rules independently
- **Unique Catch-All Variables**: `frl_tax_catch_all_slug` vs `frl_cpt_catch_all_slug` prevent conflicts
- **Independent Caching**: Each feature has its own cache keys and validation
- **No Cross-Feature Communication**: Features never call methods or access properties of other features

## ⚙️ Configuration Matrix


### **Feature Composition Examples**

#### Scenario: Multiple Features Active
```
Configuration:
- translate_post_base: "en|blog\nit|blog"
- remove_cpt_base: "service"
- remove_tax_base: "category"
- WordPress Permalink: /%category%/%postname%/

Results:
✅ Posts: /en/blog/tech/my-article/ (post base + category)
✅ CPT: /my-service/ (base removed)
✅ Tax: /tech/ (base removed)
✅ All work independently without conflicts
```

#### Scenario: CPT with Blog Integration vs Base Removal
```
Configuration A (Integration):
- translate_post_base: "en|blog\nit|blog"
- integrate_cpt_with_blog: "product"
Result: /en/blog/products/laptop/

Configuration B (Removal):
- remove_cpt_base: "product"
Result: /laptop/

Note: Choose one approach per CPT - integration OR removal
```

#### Scenario: CPT Translation vs Base Removal Conflict
```
Configuration (both active):
- translate_cpt_slugs_product: "en|products\nit|prodotti"
- remove_cpt_base: "product"

Result:
✅ Translation WINS: /en/products/laptop/ and /it/prodotti/portatile/
❌ Removal automatically bypassed via exclusion system

Note: Translation has higher priority (20-21) and exclusion patterns prevent removal from interfering
```

### **Core Configuration Options**

| Option | Affects Features | WordPress Requirement | Example |
|--------|------------------|----------------------|---------|
| `translate_post_base` | Post Archive, Post Single | Pretty permalinks enabled | `"en\|news\nit\|notizie"` |
| `translate_cpt_slugs_{cpt}` | CPT Archive, CPT Single | CPT in `FRL_REWRITER_MULTILINGUAL_CPT` | `"en\|services\nit\|servizi"` |
| `integrate_cpt_with_blog` | CPT Blog Integration | `translate_post_base` configured | `"product\nservice"` |
| `remove_tax_base` | Taxonomy Base Removal | Public taxonomies exist | `"category\ntag"` |
| `remove_cpt_base` | CPT Base Removal | Public CPTs exist | `"service\npill\nnews"` |

### **WordPress Permalink Compatibility**

| Permalink Structure | Post Base Result | Translation Features | Removal Features | Notes |
|-------------------|------------------|---------------------|------------------|--------|
| `/%postname%/` | `/en/news/article-name/` | All | All | Direct post base structure |
| `/%category%/%postname%/` | `/en/news/tech/article-name/` | All | All | Includes category in path |
| `/%year%/%monthnum%/%postname%/` | `/en/news/2024/01/article-name/` | All | All | Includes date components |
| `/%author%/%postname%/` | `/en/news/john/article-name/` | All | All | Includes author in path |
| Plain (`?p=123`) | N/A | None | All | Translation requires pretty permalinks |

### **Feature Interaction Matrix**

| Primary Feature | Compatible | Conflicts | Resolution |
|----------------|------------|-----------|-----------|
| **Post Base Translation** | All | None | Highest priority (10-11) |
| **CPT Base Translation** | All | CPT Blog Integration for same CPT | Choose one approach per CPT |
| **CPT Blog Integration** | All except conflicting CPT translation | CPT translation for same CPT | Configure `integrate_cpt_with_blog` |
| **Taxonomy Base Removal** | All | None | Exclusion patterns prevent conflicts |
| **CPT Base Removal** | All | None | Exclusion patterns prevent conflicts |

### **Quick Configuration Examples**

#### Post Base Translation (adapts to permalink structure)
```
translate_post_base: "en|news\nit|notizie"
Permalink: /%category%/%postname%/
→ /en/news/tech/article-name/ and /it/notizie/tecnologia/nome-articolo/

Permalink: /%postname%/
→ /en/news/article-name/ and /it/notizie/nome-articolo/
```

#### CPT Base Removal (specific CPTs)
```
remove_cpt_base: "service\npill\nnews"
→ /my-service/ instead of /service/my-service/
→ /my-pill/ instead of /pill/my-pill/
→ /breaking-story/ instead of /news/breaking-story/
```

#### Full Multilingual Setup with Blog Integration
```
translate_post_base: "en|blog\nit|blog"
translate_cpt_slugs_product: "en|products\nit|prodotti"
integrate_cpt_with_blog: "product"
→ /en/blog/products/laptop/ and /it/blog/prodotti/portatile/
```

### **URL Pattern Matrix**

| Configuration | WordPress Permalink | Resulting URL Pattern | Notes |
|---------------|-------------------|----------------------|-------|
| **Post Base Only** | `/%postname%/` | `/en/news/article-name/` | Direct structure |
| **Post Base Only** | `/%category%/%postname%/` | `/en/news/tech/article-name/` | Category included |
| **Post Base Only** | `/%year%/%postname%/` | `/en/news/2024/article-name/` | Date included |
| **CPT Base Removal** | Any | `/my-service/` | Removes `/service/` prefix |
| **CPT Translation** | Any | `/en/services/my-service/` | Translates CPT base |
| **Blog Integration** | `/%category%/%postname%/` | `/en/blog/products/laptop/` | Integrates with post base |
| **Taxonomy Removal** | Any | `/tech/` | Removes `/category/` prefix |
| **Combined Features** | `/%category%/%postname%/` | Multiple URLs possible | Features compose together |

## 🏗️ System Architecture

### Processing Order (by Priority)

The Coordinator sorts and executes features based on the priority returned by their `get_priority()` method. Lower numbers run first.

- **Priority 10**: Post Archive Base Translation
- **Priority 15**: CPT Archive Base Translation
- **Priority 20**: Post Single Base Translation
- **Priority 25**: CPT Single Base Translation
- **Priority 30**: CPT Blog Integration
- **Priority 35**: Taxonomy Base Removal (catch-all: `frl_tax_catch_all_slug`)
- **Priority 40**: CPT Base Removal (catch-all: `frl_cpt_catch_all_slug`)


### **URL Processing Flow**

**Incoming URLs**: First-match-wins (priority order)
**Outgoing URLs**: Composition (all applicable features transform)

### **Performance Optimizations**
- Configuration caching (all `frl_get_option()` calls)
- Rule generation caching (per feature + config hash)
- Re-entrancy guards (prevent duplicate processing)
- Context-aware processing (skip admin/AJAX)

## 🔍 Debugging

```php
// Get system status
$debug = Frl_Rewriter_Path_Utils::get_debug_info();

// Clear caches
Frl_Rewriter_Path_Utils::clear_static_caches();

// Force refresh
Frl_Rewriter::force_rules_refresh();
```

### **Common Issues**
- **404 on translated URLs**: Visit Settings > Permalinks to flush rules
- **Wrong language**: Clear rewriter cache
- **Performance**: Verify caching system active

## 📁 System Files

### **Core Components**
- `class-rewriter.php` - Main facade and URL transformation
- `class-rewriter-coordinator.php` - Feature management and validation
- `class-rewriter-path-utils.php` - Shared utilities and caching
- `features/abstract-base-feature.php` - Base class for all features

### **Feature Implementation Files**
- `features/class-post-archive-base-translation-feature.php`
- `features/class-post-single-base-translation-feature.php`
- `features/class-cpt-archive-base-translation-feature.php`
- `features/class-cpt-single-base-translation-feature.php`
- `features/class-cpt-blog-integrator-feature.php`
- `features/class-taxonomy-base-removal-feature.php`
- `features/class-cpt-base-removal-feature.php`
