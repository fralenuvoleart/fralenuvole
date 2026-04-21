## Analysis Report: frl_get_auth_cookie_user & frl_has_access

### frl_get_auth_cookie_user

**Logic Flow:**
1. Static cache check → return if cached
2. Cookie detection (3 fallback strategies)
3. Manual cookie parsing (4 elements)
4. Database query (JOIN for caps, simple SELECT for others)
5. Cache and return

**Logic Issues:**
- HMAC validation missing: Cookie parsing extracts username but doesn't validate the HMAC hash. This is a security concern - anyone could craft a cookie with a valid username.

**Performance:**
- Static caching works correctly
- Single JOIN query for capabilities
- Targeted field queries
- Cookie scanning loop is O(n) but only runs as fallback

---

### frl_has_access

**Logic Flow:**
1. **Early loading path** (before plugins_loaded):
   - Get caps via frl_get_auth_cookie_user('caps')
   - If superadmin check → also get ID via frl_get_auth_cookie_user('ID')
   - Check capability in caps array
   - Fallback to administrator role check
2. **Normal path** (after plugins_loaded):
   - Standard WordPress current_user_can
   - Migrate mode bypass
   - User ID 1 bypass
   - Cached capability check

**Logic Issues:**
- Inconsistent superadmin ID check: Early path uses FRL_PLUGIN_SUPERADMIN_ID constant, normal path hardcodes 1
- Administrator bypass is too broad: Any capability check passes if user has administrator role, even if the specific capability isn't granted

**Performance Issues:**
- Superadmin check makes 2 DB queries: Lines 177 and 183 call frl_get_auth_cookie_user() twice - once for caps, once for ID
- No caching in early loading path: Normal path uses frl_cache_remember(), early path doesn't
- Redundant administrator check: If user has administrator role, all capability checks pass without actually checking the specific capability

---

### Summary

| Aspect | frl_get_auth_cookie_user | frl_has_access |
|--------|-------------------------|----------------|
| Logic Correctness | HMAC not validated | Inconsistent checks |
| Performance | Good | 2 queries for superadmin |
| Caching | Static cache | Missing in early path |

**Recommendation**: The superadmin check in frl_has_access should be optimized to avoid the second database query, and the early loading path should use caching like the normal path does.