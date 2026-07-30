#!/usr/bin/env python3
"""Insert Coding Plan routes into routes/api.php"""
import sys

path = 'routes/api.php'
with open(path, 'r') as f:
    content = f.read()

# Step 1: Add import after VendorController import
import_line = "use App\\Http\\Controllers\\VendorController;\n"
coding_import = "use App\\Http\\Controllers\\CodingPlanController;\n"
if coding_import not in content:
    content = content.replace(import_line, import_line + coding_import, 1)
    print("Import added")
else:
    print("Import already exists")

# Step 2: Insert routes after GroupController index line
insert_block = """
    // Coding Plan Accounts (Admin)
    Route::get('/coding_plan/accounts', [CodingPlanController::class, 'accounts']);
    Route::post('/coding_plan/accounts', [CodingPlanController::class, 'storeAccount']);
    Route::put('/coding_plan/accounts/{id}', [CodingPlanController::class, 'updateAccount']);
    Route::delete('/coding_plan/accounts/{id}', [CodingPlanController::class, 'destroyAccount']);
    Route::post('/coding_plan/accounts/{id}/reset_usage', [CodingPlanController::class, 'resetUsage']);
    Route::get('/coding_plan/accounts/{id}/usage', [CodingPlanController::class, 'accountUsage']);
    Route::post('/coding_plan/plans/{id}/attach', [CodingPlanController::class, 'attachPlan']);
    Route::post('/coding_plan/plans/{id}/detach', [CodingPlanController::class, 'detachPlan']);
    Route::get('/coding_plan/plans', [CodingPlanController::class, 'plans']);
    Route::get('/coding_plan/stats', [CodingPlanController::class, 'stats']);
"""

target = "    Route::get('/group/', [GroupController::class, 'index']);\n"
if "coding_plan/accounts" not in content:
    if target in content:
        content = content.replace(target, target + insert_block + "\n", 1)
        print("Routes inserted")
    else:
        print("ERROR: target line not found")
        sys.exit(1)
else:
    print("Routes already exist")

with open(path, 'w') as f:
    f.write(content)
print("File saved")