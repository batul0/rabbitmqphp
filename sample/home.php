<?php
// Minimal error handling for clean output
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// Require RabbitMQ libs
require_once('/home/vboxuser/git/rabbitmqphp/path.inc');
require_once('/home/vboxuser/git/rabbitmqphp/get_host_info.inc');
require_once('/home/vboxuser/git/rabbitmqphp/rabbitMQLib.inc');

// 1) Read session cookie
$sid = $_COOKIE['sid'] ?? '';

// 2) If missing, bounce to login
if ($sid === '') {
  header('Location: index.html');
  exit;
}

// 3) Validate session via RabbitMQ
try {
  $client = new rabbitMQClient('testRabbitMQ.ini', 'loginServer');
  $res = $client->send_request(['type' => 'validate_session', 'sessionId' => $sid]);

  if (!is_array($res) || empty($res['success'])) {
    // Invalid/expired → clear cookie and redirect
    setcookie('sid', '', time() - 3600, '/');
    header('Location: index.html');
    exit;
  }

  $username = htmlspecialchars($res['username'] ?? 'there', ENT_QUOTES, 'UTF-8');
} catch (Throwable $e) {
  // On error, fail closed
  header('Location: index.html');
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Home Page</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body style="background-color: #967bb6;">
  <div id="navbar-placeholder">
    <?php @readfile(__DIR__ . '/navbar.html'); ?>
  </div>

  <div style="background-color: #967bb6;" class="p-5 mb-4 text-white text-center">
    <div class="container-fluid py-5">
      <h1 class="display-3 fw-bold">Welcome, <?php echo $username; ?>!</h1>
      <div class="container mt-3">
        <div class="d-flex justify-content-end">
            <a class="btn btn-outline-light" href="logout.php">Logout</a>
        </div>
        </div>
      <p class="col-md-8 mx-auto fs-4">
        You want to learn more about....you have come to the right place :))
        Look down below to see what we offer on our website...Enjoy!!
      </p>
    </div>
    <img src="something.jpg" class="img-fluid" alt="image pending...">
  </div>

  <div class="container my-5">
    <div class="row text-center">
      <div class="col-lg-4 mb-4">
        <div style="background-color: #e6e6fa;" class="card h-100">
          <div class="card-body">
            <h5 class="card-title">feature</h5>
            <p class="card-text">pending...</p>
            <a href="blank.php" class="stretched-link"></a>
          </div>
        </div>
      </div>
      <div class="col-lg-4 mb-4">
        <div style="background-color: #e6e6fa;" class="card h-100">
          <div class="card-body">
            <h5 class="card-title">feature</h5>
            <p class="card-text">pending...</p>
            <a href="blank.php" class="stretched-link"></a>
          </div>
        </div>
      </div>
      <div class="col-lg-4 mb-4">
        <div style="background-color: #e6e6fa;" class="card h-100">
          <div class="card-body">
            <h5 class="card-title">feature</h5>
            <p class="card-text">pending...</p>
            <a href="blank.php" class="stretched-link"></a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <footer class="bg-dark text-white text-center py-3 mt-5 fixed-bottom">
    <div class="container">
      <p class="mb-0">&copy; 2025 Uhhh.....Inc @. All Rights Reserved</p>
    </div>
  </footer>
</body>
</html>
