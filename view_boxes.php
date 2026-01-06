<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Check if user is admin
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;

// Handle delete request (only if admin)
if (isset($_GET['delete']) && $is_admin) {
    $box_id = intval($_GET['delete']);
    
    try {
        // Check if box exists before deleting
        $check_sql = "SELECT id FROM boxes WHERE id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$box_id]);
        
        if ($check_stmt->rowCount() > 0) {
            // Delete the box (cascade will handle related records)
            $delete_sql = "DELETE FROM boxes WHERE id = ?";
            $delete_stmt = $pdo->prepare($delete_sql);
            
            if ($delete_stmt->execute([$box_id])) {
                $_SESSION['message'] = "Box deleted successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error deleting box!";
                $_SESSION['message_type'] = "error";
            }
        } else {
            $_SESSION['message'] = "Box not found!";
            $_SESSION['message_type'] = "error";
        }
    } catch (PDOException $e) {
        $_SESSION['message'] = "Database error: " . $e->getMessage();
        $_SESSION['message_type'] = "error";
    }
    
    // Redirect to refresh page
    header("Location: view_boxes.php");
    exit();
}

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_filter = '';
$params = [];

if (!empty($search)) {
    $search_filter = "WHERE b.box_name LIKE ? OR b.address LIKE ? OR u.username LIKE ?";
    $search_term = "%$search%";
    $params = [$search_term, $search_term, $search_term];
}

try {
    // Fetch all boxes with their creator information
    $sql = "SELECT b.*, u.username as creator_name 
            FROM boxes b 
            LEFT JOIN users u ON b.created_by = u.id 
            $search_filter
            ORDER BY b.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $boxes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Find duplicate boxes (same name)
    $box_names = [];
    $duplicate_boxes = [];
    foreach ($boxes as $box) {
        $box_name = strtolower(trim($box['box_name']));
        if (isset($box_names[$box_name])) {
            if (!isset($duplicate_boxes[$box_name])) {
                $duplicate_boxes[$box_name] = [$box_names[$box_name]];
            }
            $duplicate_boxes[$box_name][] = $box['id'];
        } else {
            $box_names[$box_name] = $box['id'];
        }
    }
    
} catch (PDOException $e) {
    die("Error fetching boxes: " . $e->getMessage());
}

// Get total boxes count
try {
    $count_sql = "SELECT COUNT(*) as total FROM boxes";
    $count_stmt = $pdo->query($count_sql);
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $total_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Boxes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <style>
        .box-card {
            transition: transform 0.2s;
            min-height: 350px;
        }
        .box-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .delete-btn {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .alert {
            margin-top: 20px;
        }
        .card-body {
            padding-bottom: 70px;
        }
        .card-actions {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
        }
        .address-truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .duplicate-warning {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: #ffc107;
            color: #000;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            z-index: 1;
        }
        .duplicate-box {
            border-left: 4px solid #ffc107;
        }
        .search-box {
            max-width: 400px;
        }
        .stats-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
        }
        .badge-duplicate {
            background-color: #ffc107;
            color: #000;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .container {
                max-width: 100%;
                padding: 15px;
            }
            
            .search-box {
                max-width: 100%;
            }
            
            .stats-card {
                margin-bottom: 15px;
            }
        }

        @media (max-width: 992px) {
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
                padding: 20px 15px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .search-box {
                max-width: 100%;
                margin-bottom: 15px;
            }
            
            .search-box input {
                font-size: 14px;
                padding: 10px;
            }
            
            .search-box button {
                padding: 10px 15px;
                font-size: 14px;
            }
            
            .btn-group {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }
            
            .btn-group .btn {
                width: 100%;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .boxes-table {
                font-size: 14px;
            }
            
            .boxes-table th,
            .boxes-table td {
                padding: 8px;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                padding: 15px 10px;
            }
            
            .header h1 {
                font-size: 20px;
            }
            
            .search-box {
                margin-bottom: 12px;
            }
            
            .search-box input {
                font-size: 13px;
                padding: 8px;
            }
            
            .search-box button {
                padding: 8px 12px;
                font-size: 13px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .stats-card {
                padding: 15px;
                text-align: center;
            }
            
            .stats-number {
                font-size: 24px;
            }
            
            .stats-label {
                font-size: 12px;
            }
            
            .boxes-table {
                font-size: 12px;
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .boxes-table th,
            .boxes-table td {
                padding: 6px;
                min-width: 80px;
            }
            
            .badge {
                font-size: 10px;
                padding: 2px 6px;
            }
            
            .btn-sm {
                padding: 5px 8px;
                font-size: 11px;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 5px;
            }
            
            .header {
                padding: 10px 5px;
            }
            
            .header h1 {
                font-size: 18px;
            }
            
            .search-box {
                flex-direction: column;
                gap: 8px;
            }
            
            .search-box input {
                width: 100%;
                margin-bottom: 8px;
            }
            
            .search-box button {
                width: 100%;
                padding: 10px;
            }
            
            .stats-card {
                padding: 12px;
            }
            
            .stats-number {
                font-size: 20px;
            }
            
            .stats-label {
                font-size: 11px;
            }
            
            .boxes-table {
                font-size: 11px;
            }
            
            .boxes-table th,
            .boxes-table td {
                padding: 4px;
                min-width: 60px;
            }
            
            .badge {
                font-size: 9px;
                padding: 1px 4px;
            }
            
            .btn-sm {
                padding: 4px 6px;
                font-size: 10px;
            }
            
            .table-responsive {
                margin: 0 -5px;
                padding: 0 5px;
            }
        }

        @media (max-width: 320px) {
            .header h1 {
                font-size: 16px;
            }
            
            .stats-number {
                font-size: 18px;
            }
            
            .stats-label {
                font-size: 10px;
            }
            
            .boxes-table {
                font-size: 10px;
            }
            
            .boxes-table th,
            .boxes-table td {
                padding: 3px;
                min-width: 50px;
            }
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <!-- Header with search and actions -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>All Boxes</h1>
            <div>
                <a href="admin.php" class="btn btn-success">
                    Admin
                </a>
                <a href="dashboard.php" class="btn btn-secondary">
                    Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Search Box -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-8">
                        <div class="input-group">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Search by box name, address, or creator..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button class="btn btn-primary" type="submit">
                                Search
                            </button>
                            <?php if (!empty($search)): ?>
                                <a href="view_boxes.php" class="btn btn-outline-secondary">
                                    Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="showDuplicates" 
                                   onclick="toggleDuplicates()">
                            <label class="form-check-label" for="showDuplicates">
                                Highlight Duplicates
                            </label>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h5 class="card-title">Total Boxes</h5>
                        <h2 class="text-primary"><?php echo $total_count; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h5 class="card-title">Duplicates</h5>
                        <h2 class="text-warning"><?php echo count($duplicate_boxes); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h5 class="card-title">Admin Status</h5>
                        <h2>
                            <span class="badge bg-<?php echo $is_admin ? 'success' : 'secondary'; ?>">
                                <?php echo $is_admin ? 'Admin' : 'User'; ?>
                            </span>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body text-center">
                        <h5 class="card-title">Viewing</h5>
                        <h2><?php echo count($boxes); ?></h2>
                        <small><?php echo !empty($search) ? 'Search Results' : 'All Boxes'; ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Duplicate Warning Alert -->
        <?php if (!empty($duplicate_boxes)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <h5>Duplicate Boxes Detected</h5>
                <p>The following box names appear multiple times:</p>
                <ul>
                    <?php foreach($duplicate_boxes as $name => $ids): ?>
                        <li><strong><?php echo htmlspecialchars($name); ?></strong> (IDs: <?php echo implode(', ', $ids); ?>)</li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type'] == 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>
        
        <!-- Boxes Grid -->
        <div class="row" id="boxesGrid">
            <?php if (!empty($boxes)): ?>
                <?php foreach($boxes as $row): 
                    $is_duplicate = false;
                    $box_name_lower = strtolower(trim($row['box_name']));
                    foreach ($duplicate_boxes as $dup_name => $dup_ids) {
                        if (strtolower($dup_name) === $box_name_lower) {
                            $is_duplicate = true;
                            break;
                        }
                    }
                ?>
                    <div class="col-md-4 mb-4 box-item <?php echo $is_duplicate ? 'duplicate-box' : ''; ?>" 
                         data-boxname="<?php echo strtolower(trim($row['box_name'])); ?>">
                        <div class="card box-card h-100">
                            <div class="card-body position-relative">
                                <?php if ($is_duplicate): ?>
                                    <div class="duplicate-warning">
                                        Duplicate
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($is_admin): ?>
                                    <button type="button" class="btn btn-danger btn-sm delete-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#deleteModal"
                                            data-boxid="<?php echo $row['id']; ?>"
                                            data-boxname="<?php echo htmlspecialchars($row['box_name']); ?>">
                                        Delete
                                    </button>
                                <?php endif; ?>
                                
                                <h5 class="card-title">
                                    <?php echo htmlspecialchars($row['box_name']); ?>
                                    <?php if ($is_duplicate): ?>
                                        <span class="badge badge-duplicate ms-2">Duplicate</span>
                                    <?php endif; ?>
                                </h5>
                                <h6 class="card-subtitle mb-3 text-muted">
                                    ID: <span class="badge bg-secondary"><?php echo $row['id']; ?></span>
                                </h6>
                                
                                <ul class="list-group list-group-flush">
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span><strong>Total Cores:</strong></span>
                                        <span class="badge bg-primary"><?php echo $row['total_cores']; ?></span>
                                    </li>
                                    <li class="list-group-item">
                                        <strong>Location:</strong> 
                                        <?php 
                                        if ($row['location_lat'] && $row['location_lng']) {
                                            echo '<small class="text-muted">' . $row['location_lat'] . ', ' . $row['location_lng'] . '</small>';
                                        } else {
                                            echo '<span class="text-warning">Not set</span>';
                                        }
                                        ?>
                                    </li>
                                    <li class="list-group-item">
                                        <strong>Address:</strong> 
                                        <div class="address-truncate mt-1">
                                            <?php 
                                            echo $row['address'] ? htmlspecialchars($row['address']) : '<span class="text-warning">Not provided</span>';
                                            ?>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between">
                                        <span><strong>Created By:</strong></span>
                                        <span><?php echo htmlspecialchars($row['creator_name'] ?: 'Unknown'); ?></span>
                                    </li>
                                    <li class="list-group-item">
                                        <strong>Created:</strong> 
                                        <?php echo date("F j, Y, g:i a", strtotime($row['created_at'])); ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="alert alert-info text-center py-5">
                        <h4>No boxes found</h4>
                        <p class="mb-0">
                            <?php if (!empty($search)): ?>
                                No boxes match your search "<?php echo htmlspecialchars($search); ?>"
                            <?php else: ?>
                                No boxes found in the database. Click "Add New Box" to create your first box.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Summary Card -->
        <?php if (!empty($boxes)): ?>
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <h5 class="card-title">Summary</h5>
                                    <p class="card-text">
                                        Total Boxes: <strong><?php echo $total_count; ?></strong><br>
                                        Displaying: <strong><?php echo count($boxes); ?></strong><br>
                                        Duplicates: <strong><?php echo count($duplicate_boxes); ?></strong>
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <h5 class="card-title">Permissions</h5>
                                    <p class="card-text">
                                        Admin Status: 
                                        <span class="badge bg-<?php echo $is_admin ? 'success' : 'secondary'; ?>">
                                            <?php echo $is_admin ? 'Admin' : 'Regular User'; ?>
                                        </span><br>
                                        Delete Permission: 
                                        <strong><?php echo $is_admin ? 'Enabled' : 'Disabled'; ?></strong>
                                    </p>
                                </div>
                                <div class="col-md-4">
                                    <h5 class="card-title">Search Info</h5>
                                    <p class="card-text">
                                        <?php if (!empty($search)): ?>
                                            Searching for: <strong>"<?php echo htmlspecialchars($search); ?>"</strong><br>
                                            <a href="view_boxes.php" class="text-decoration-none">
                                                View all boxes
                                            </a>
                                        <?php else: ?>
                                            Showing all boxes
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                    </div>
                    <p>Are you sure you want to delete box "<span id="boxNameToDelete" class="fw-bold"></span>"?</p>
                    <div class="alert alert-danger">
                        <strong>Warning:</strong> This action cannot be undone. All associated cores and connections will also be deleted!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                        Delete Box
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle delete modal
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteModal');
            
            deleteModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const boxId = button.getAttribute('data-boxid');
                const boxName = button.getAttribute('data-boxname');
                
                document.getElementById('boxNameToDelete').textContent = boxName;
                document.getElementById('confirmDeleteBtn').href = 'view_boxes.php?delete=' + boxId;
            });
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-danger)');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });

        // Toggle duplicate highlighting
        function toggleDuplicates() {
            const checkBox = document.getElementById('showDuplicates');
            const duplicateBoxes = document.querySelectorAll('.duplicate-box');
            
            if (checkBox.checked) {
                // Show all boxes but highlight duplicates
                document.querySelectorAll('.box-item').forEach(box => {
                    box.style.display = 'block';
                });
                duplicateBoxes.forEach(box => {
                    box.style.borderLeft = '4px solid #ffc107';
                });
            } else {
                // Show all boxes normally
                document.querySelectorAll('.box-item').forEach(box => {
                    box.style.display = 'block';
                });
            }
        }

        // Filter duplicates only
        function showDuplicatesOnly() {
            const checkBox = document.getElementById('showDuplicates');
            const allBoxes = document.querySelectorAll('.box-item');
            
            if (checkBox.checked) {
                allBoxes.forEach(box => {
                    if (box.classList.contains('duplicate-box')) {
                        box.style.display = 'block';
                    } else {
                        box.style.display = 'none';
                    }
                });
            } else {
                allBoxes.forEach(box => {
                    box.style.display = 'block';
                });
            }
        }
    </script>
</body>
</html>