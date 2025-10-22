# Cluster Validation Database Investigation

## Problem
Running `spiralling_investigation_validate_clusters.php` on remote server (bulk3) creates a 0-byte SQLite database and produces no output file.

## What We've Discovered

### Script Overview
- **Script**: `spiralling_investigation_validate_clusters.php`
- **Purpose**: Validates whether isochrone parameters should vary by cluster
- **Database**: Creates SQLite files per cluster: `/tmp/cluster_{N}_optimization.db`
- **Output**: JSON summary at specified path (e.g., `/tmp/cluster_validation.json`)

### Current State on Remote Server (bulk3)
```bash
# Input file exists and looks valid:
/tmp/group_clusters_representatives.csv (588 bytes, created Oct 19)
- Contains 5 clusters (0-4) with 3 representative groups each
- Total: 15 groups across 5 clusters

# Database file created but empty:
/tmp/cluster_0_optimization.db (0 bytes, created Oct 21)
- Script started and created the database
- But crashed before any iterations ran
- No data written to SQLite

# Output file missing:
/tmp/cluster_validation.json - does not exist
- Script never completed
```

### Command That Was Run
```bash
php spiralling_investigation_validate_clusters.php \
  --representatives=/tmp/group_clusters_representatives.csv \
  --iterations=50 \
  --output=/tmp/cluster_validation.json
```

### User Saw This Output
```
[19263/19263] Processing message 117089181: WANTED: Laptop (Woodstock OX20)
  ✓ Complete - Replies: 0, Final users reached:

Aggregate metrics: (all zeros)
```

**Important**: This output suggests the simulator ran and processed messages, but then the aggregation phase showed all zeros (which is normal for first run).

## How the Script Works

### Script Flow
1. Load representatives from CSV → Group by cluster
2. For each cluster:
   - Create `/tmp/cluster_{N}_optimization.db` (SQLite)
   - Run `IsochroneParameterOptimizer` with N iterations
   - Each iteration:
     - Sample parameters using Bayesian optimization
     - Run `MessageIsochroneSimulatorWithTemporal`
     - Evaluate success rate
     - Store iteration in SQLite
3. Analyze parameter differences across clusters
4. Export results to JSON

### Key Files
- **Main script**: `iznik-server/scripts/cli/spiralling_investigation_validate_clusters.php`
- **Optimizer**: `iznik-server/scripts/cli/spiralling_investigation_optimize_parameters.php`
- **Simulator**: `iznik-server/scripts/cli/spiralling_investigation_simulate_temporal.php`
  - Extends: `spiralling_investigation_simulate.php`

## Why Database is Empty

The 0-byte database indicates:
1. ✓ Script started (database file created)
2. ✓ SQLite initialized (tables created)
3. ✗ No optimization iterations ran (no data inserted)
4. ✗ Script crashed before completion

## Next Steps to Diagnose

### 1. Run with Error Output
```bash
cd /var/www/iznik/scripts/cli

# Run with just 2 iterations for quick testing
php spiralling_investigation_validate_clusters.php \
  --representatives=/tmp/group_clusters_representatives.csv \
  --iterations=2 \
  --output=/tmp/cluster_validation.json 2>&1 | tee /tmp/validation_run.log

# Check what happened
head -200 /tmp/validation_run.log
ls -lh /tmp/cluster_*_optimization.db
```

### 2. Expected Output (if working)
```
=== Cluster Parameter Validation ===
Loaded 15 representative groups
Found 5 clusters

=== CLUSTER 0 ===
Representative groups: Hereford_Freegle, Presteigne-Freegle, BasingstokeFreegle
Group IDs: 21465, 126671, 21243

Running optimization for cluster 0...
Initializing SQLite database at /tmp/cluster_0_optimization.db
Computing active hours pattern from historical reply data...
Created optimization run 1

=== STAGE 1: SPATIAL PARAMETER OPTIMIZATION ===
Iterations: 2
Sample size per iteration: 50 messages

--- Iteration 1/2 ---
Testing parameters:
{...}
Running simulation...
```

### 3. Check Database Contents (if populated)
```bash
sqlite3 /tmp/cluster_0_optimization.db "SELECT COUNT(*) FROM optimization_iterations;"
sqlite3 /tmp/cluster_0_optimization.db "SELECT * FROM optimization_runs;"
```

## Possible Causes

1. **No messages found** for the representative groups in date range
2. **PHP error** during active hours computation or simulation
3. **Database connection issue** with MySQL during simulation
4. **Memory exhaustion** processing large datasets
5. **Missing isochrones** in cache causing simulation to return empty results

## Code Changes Made

### Fixed Help Text
Updated `spiralling_investigation_validate_clusters.php` line 303:
- Changed script name in help text from `validate_cluster_parameters.php`
- To correct name: `spiralling_investigation_validate_clusters.php`
- Also updated example workflow script names

## Resume Point

When you return, run the diagnostic command above and share the output. Look for:
- Error messages (PHP warnings, exceptions)
- Whether it loads representatives successfully
- Whether it starts processing cluster 0
- Where exactly it fails

The output will tell us if it's:
- Input data issue (no messages for those groups)
- Code bug (exception during processing)
- Resource issue (timeout, memory)
