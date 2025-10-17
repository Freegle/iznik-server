# Isochrone Parameter Optimization

Two-stage Bayesian optimization system for finding optimal isochrone expansion parameters.

## Overview

This system uses historical message and reply data to find the best parameters for:
- **Spatial parameters**: How large the isochrone should grow (initialMinutes, maxMinutes, minUsers, etc.)
- **Temporal parameters**: When to expand the isochrone (timing curve breakpoints and intervals)

The goal is to achieve a 95% success rate where success means:
- Either reaching the eventual taker OR getting sufficient replies (numReplies)
- Within 112 adjusted hours (only counting active hours: typically 8am-8pm based on historical reply patterns)

## Files

- `optimize_isochrone_parameters.php` - Main optimization script
- `simulate_message_isochrones_temporal.php` - Extended simulator with configurable temporal curves
- `simulate_message_isochrones.php` - Base simulator (already exists)

## Quick Start

### Run Both Stages (Recommended)

```bash
cd /home/edward/FreegleDockerWSL/iznik-server
php scripts/cli/optimize_isochrone_parameters.php --stage=both
```

This will:
1. Optimize spatial parameters (50 iterations, ~2.5 hours)
2. Optimize temporal curve parameters (50 iterations, ~2.5 hours)
3. Output the best combined parameter set

### Run Individual Stages

```bash
# Stage 1: Spatial parameters only
php scripts/cli/optimize_isochrone_parameters.php --stage=1 --stage1-iterations=50

# Stage 2: Temporal curve only (requires stage 1 results)
php scripts/cli/optimize_isochrone_parameters.php --stage=2 --stage2-iterations=50
```

### Export Results to CSV

```bash
php scripts/cli/optimize_isochrone_parameters.php \
  --export-csv=/tmp/optimization_results.csv \
  --db-path=/tmp/isochrone_optimization.db
```

## Options

| Option | Description | Default |
|--------|-------------|---------|
| `--stage=<1\|2\|both>` | Which optimization stage to run | Required |
| `--stage1-iterations=N` | Number of iterations for stage 1 | 50 |
| `--stage2-iterations=N` | Number of iterations for stage 2 | 50 |
| `--start=DATE` | Start date for historical data (YYYY-MM-DD) | 60 days ago |
| `--end=DATE` | End date for historical data (YYYY-MM-DD) | 7 days ago |
| `--groups=ID,ID,...` | Comma-separated group IDs to include | All groups |
| `--sample-size=N` | Messages to evaluate per iteration | 50 |
| `--db-path=PATH` | SQLite database path for results | `/tmp/isochrone_optimization.db` |
| `--export-csv=PATH` | Export results to CSV file | - |

## How It Works

### Stage 1: Spatial Parameter Optimization

**Fixed temporal parameters** (current production values):
```php
'breakpoint1' => 12,    // hours
'breakpoint2' => 24,    // hours
'interval1' => 4,       // hours (expand every 4h in first 12h)
'interval2' => 6,       // hours (expand every 6h in hours 12-24)
'interval3' => 8        // hours (expand every 8h after 24h)
```

**Optimized spatial parameters**:
- `initialMinutes`: 3-15 (starting isochrone size)
- `maxMinutes`: 45-120 (maximum isochrone size)
- `increment`: 3-10 (step size for expanding)
- `minUsers`: 50-200 (users to reach per expansion)
- `activeSince`: 60-180 days (user activity lookback)
- `numReplies`: 5-9 (stop expanding when this many replies received)

### Stage 2: Temporal Curve Optimization

**Fixed**: Best spatial parameters from Stage 1

**Optimized temporal parameters**:
- `breakpoint1`: 6-18 hours (when to transition to slower expansion)
- `breakpoint2`: 18-48 hours (when to transition to slowest expansion)
- `interval1`: 2-6 hours (early expansion frequency)
- `interval2`: 4-10 hours (middle expansion frequency)
- `interval3`: 6-12 hours (late expansion frequency)

**Constraints**:
- `breakpoint1 < breakpoint2`
- `interval1 <= interval2 <= interval3`

### Optimization Algorithm

**Iterations 1-15**: Latin Hypercube Sampling (LHS)
- Systematic exploration of parameter space
- Divides each parameter range into bins
- Ensures good coverage

**Iterations 16-50**: Bayesian-inspired sampling
- Focuses search near high-scoring parameter sets
- Adds Gaussian noise for exploration
- Balances exploitation (refine best) vs exploration (find new peaks)

### Evaluation Metric

For each parameter set:
1. Sample N messages from historical data (default: 50)
2. Simulate isochrone expansion using those parameters
3. For each message, check success:
   - Did we reach the taker OR get numReplies?
   - Within 112 adjusted hours?
4. Return success rate (0.0 to 1.0)

**Goal**: Find parameters achieving ≥95% success rate

## Active Hours Pattern

The system pre-computes which hours are "active" based on historical reply data:

```sql
SELECT HOUR(cm.date) as hour_of_day,
       COUNT(*) * 100.0 / SUM(COUNT(*)) OVER () as pct_of_total
FROM chat_messages cm
WHERE cm.type = 'Interested'
  AND cm.date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
GROUP BY HOUR(cm.date)
```

Hours with ≥3% of total replies are marked as "active". Only these hours count toward the 112-hour limit.

## Results Storage

All results stored in local SQLite database (default: `/tmp/isochrone_optimization.db`)

### Tables

**optimization_runs**:
- id, stage, search_space, fixed_params
- start_date, end_date, sample_size
- status, best_parameters, best_score
- created, completed

**optimization_iterations**:
- id, run_id, iteration
- parameters (JSON), score
- created

### Querying Results

```bash
# View results in SQLite
sqlite3 /tmp/isochrone_optimization.db

# Get best parameters from latest run
SELECT best_parameters, best_score
FROM optimization_runs
ORDER BY id DESC LIMIT 1;

# View iteration history
SELECT iteration, score, parameters
FROM optimization_iterations
WHERE run_id = 1
ORDER BY score DESC LIMIT 10;
```

## Example Output

```
=== STAGE 1: SPATIAL PARAMETER OPTIMIZATION ===
Computing active hours pattern from historical reply data...
  Hour 8: 4.2% - ACTIVE
  Hour 9: 5.1% - ACTIVE
  ...
Initializing SQLite database at /tmp/isochrone_optimization.db
Created optimization run 1

--- Iteration 1/50 ---
Testing parameters:
{
    "initialMinutes": 7,
    "maxMinutes": 82,
    "increment": 6,
    "minUsers": 125,
    "activeSince": 120,
    "numReplies": 7
}
Running simulation...
  Results: 46/50 messages succeeded
Score: 92.00%

--- Iteration 2/50 ---
...

=== STAGE 1 COMPLETE ===
Best score: 93.50%
Best parameters:
{
    "initialMinutes": 5,
    "maxMinutes": 90,
    "increment": 5,
    "minUsers": 100,
    "activeSince": 90,
    "numReplies": 7
}

=== STAGE 2: TEMPORAL CURVE OPTIMIZATION ===
...
```

## Performance Notes

- Each iteration takes ~3 minutes (50 messages × 3 seconds)
- Stage 1 (50 iterations) ≈ 2.5 hours
- Stage 2 (50 iterations) ≈ 2.5 hours
- Total optimization time ≈ 5-6 hours

Can be run overnight or in background:

```bash
nohup php scripts/cli/optimize_isochrone_parameters.php --stage=both > /tmp/optimization.log 2>&1 &
```

## Analyzing Results

### Export to CSV for plotting

```bash
php scripts/cli/optimize_isochrone_parameters.php \
  --export-csv=/tmp/results.csv

# Plot in Excel, Python, R, etc.
```

### Python analysis example

```python
import pandas as pd
import matplotlib.pyplot as plt

df = pd.read_csv('/tmp/results.csv')

# Plot score over iterations
plt.plot(df['iteration'], df['score'])
plt.xlabel('Iteration')
plt.ylabel('Success Rate')
plt.title('Optimization Progress')
plt.show()

# Find best parameters
best_idx = df['score'].idxmax()
print("Best parameters:")
print(df.iloc[best_idx])
```

## Next Steps After Optimization

1. **Validate results**: Run simulations on held-out data
2. **Update production code**: Apply best parameters to `Message::expandIsochrones()`
3. **Monitor performance**: Track actual success rates vs simulated
4. **Re-optimize periodically**: As user behavior changes over time (quarterly?)

## Troubleshooting

**"No messages found for simulation"**
- Check date range includes messages with replies
- Verify groups have active users

**"Warning: No results from simulation"**
- Messages lack location data
- No active users in group

**Low success rates (<80%)**
- Increase `--sample-size` for more reliable estimates
- Expand search space bounds
- Check if 112 adjusted hours is realistic for your data
