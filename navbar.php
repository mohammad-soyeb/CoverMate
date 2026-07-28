<?php
if(session_status() == PHP_SESSION_NONE){
  session_start();
}
?>


<!DOCTYPE html>
<html>
<head>
<style>
body {
  margin: 0;
  font-family: Arial;
}

.navbar {
  background: white;
  padding: 15px 50px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0px 2px 5px rgba(0,0,0,0.1);
}

.menu a {
  margin-right: 20px;
  text-decoration: none;
  color: black;
  font-weight: bold;
}

.menu a:hover {
  color: red;
}

.badge {
  background: red;
  color: white;
  font-size: 10px;
  padding: 3px 6px;
  border-radius: 3px;
  margin-left: 5px;
}

.right {
  display: flex;
  align-items: center;
}

.right a {
  margin-left: 15px;
  text-decoration: none;
  color: black;
  font-weight: bold;
}

.logout {
  background: red;
  color: white !important;
  padding: 5px 10px;
  border-radius: 5px;
}
</style>
<link rel="stylesheet" href="ui-enhancements.css">
<script src="ui-enhancements.js" defer></script>
</head>

<body>

<div class="navbar">

  <!-- Left Menu -->
  <div class="menu">
    <a href="dashboard.php">HOME</a>
    <a href="search.php">ALL ITEMS <span class="badge">NEW</span></a>
    <a href="report.php">REPORT ITEM</a>
  </div>

  <!-- Right Side -->
  <div class="right">
    <a href="dashboard.php">👤 <?php echo $_SESSION['user']; ?></a>
    <a class="logout" href="logout.php">Logout</a>
  </div>

</div>

</body>
</html>
