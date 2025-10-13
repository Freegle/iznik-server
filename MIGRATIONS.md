# Database Migrations

This file documents database schema changes and migrations required when upgrading the codebase.

## 2025-10 - Simulation API Simplification

### Overview
The simulation API was simplified by removing the session-based architecture in favor of direct runid+index parameter queries. This makes the API stateless and easier to use.

### Changes Made
- Updated `/api/simulation` endpoint to accept `runid` and `index` parameters directly
- Removed POST endpoint for session creation
- Changed GET endpoint to query messages by `runid` and `index` instead of using sessions

### Database Impact
The `simulation_message_isochrones_sessions` table is no longer used by the application and can be dropped:

```sql
DROP TABLE IF EXISTS simulation_message_isochrones_sessions;
```

**Note:** This is optional cleanup. The table can remain in the database without causing issues if you prefer to keep historical data or want to be cautious. However, it will no longer be populated or accessed by the application code.

### Verification
After applying this change:
1. The simulation viewer at `/simulation` should still work correctly
2. Navigating between messages should function without errors
3. No sessions will be created in the database
