<?php 
$service_array = array(
  	"Terraria Server",
    "Minecraft Server",
    "Space Engineers Server",
    "Starbound Server",
    "Apache2.2"
);
if (empty($_POST) && empty($_GET)) { ?>
<html ng-app="servicesApp"><body>
<head>
<script src="//ajax.googleapis.com/ajax/libs/angularjs/1.3.5/angular.min.js"></script>
<script src="<?php echo $_SERVER['PHP_SELF']; ?>?js"></script>
<style>
	.vis {
		display:block;
	}
	.invis {
		display:none;
	}
</style>
</head>
<body ng-controller="ServiceController">
<h1>Services</h1>
<table>
	<tr ng-repeat="service in services">
    <td>{{service.name}}</td>
	  <td>{{service.current_state_msg}}</td>
	  <td class="{{GetStartClass(service.current_state_id)}}"><button ng-click="Start(service.name)">Start</button></td>
		<td class="{{GetStopClass(service.current_state_id)}}"><button ng-click="Stop(service.name)">Stop</button></td>
		<td class="{{GetRestartClass(service.current_state_id)}}"><button ng-click="Restart(service.name)">Restart</button></td>
	</tr>
</table>
</body>
</html>
<?php

} elseif (isset($_REQUEST['js'])) {
?>
var servicesApp = angular.module('servicesApp', []);

servicesApp.controller('ServiceController', function ($scope, $http, $filter) {
	$scope.services = null;
	$scope.GetStartClass = function(state_id) {
		switch(state_id) {
			case 1: // stopped
          return 'vis';
      case 2: // starting
          return 'vis';
      case 3: // stopping
          return 'invis';
      case 4: // running
      		return 'invis';
		}		
	};
	
	$scope.GetStopClass = function(state_id) {
		switch(state_id) {
			case 1: // stopped
          return 'invis';
      case 2: // starting
          return 'invis';
      case 3: // stopping
          return 'vis';
      case 4: // running
      		return 'vis';
		}		
	};
	
	$scope.GetRestartClass = function(state_id) {
		switch(state_id) {
			case 1: // stopped
          return 'invis';
      case 2: // starting
          return 'invis';
      case 3: // stopping
          return 'invis';
      case 4: // running
      		return 'vis';
		}		
	};
	$scope.MapServiceMsg = function(id) {
		state_msg = "";
		switch(id)  {
			case 1: // stopped
           state_msg = 'Stopped';                      
           break;
      case 2: // starting
           state_msg = 'Starting';
           break;
      case 3: // stopping
           state_msg = 'Stopping';
           break;
      case 4: // running
           state_msg = 'Running';
           break; 
		}
		return state_msg;
	}
  $http.get('services.php?GetServices').success(function(data) {
  	$scope.services = [];
  	angular.forEach(data, function(service, key) {
  		service.current_state_msg = $scope.MapServiceMsg(service.current_state_id); 
			$scope.services.push(service);    
	  });
	});
	$scope.Start = function(name) {
		$http.get('services.php?StartService='+name).success(function(data){
			angular.forEach($scope.services, function(service, key) {
				if (service.name == name) {
					service.current_state_msg = $scope.MapServiceMsg(data.current_state_id);
	  			service.current_state_id = data.current_state_id;
				}
		  });
			//var filtered = $filter('filter')($scope.services, data.name);
		});
	};
	$scope.Stop = function(name) {
		$http.get('services.php?StopService='+name).success(function(data){
			angular.forEach($scope.services, function(service, key) {
				if (service.name == name) {
					service.current_state_msg = $scope.MapServiceMsg(data.current_state_id);
	  			service.current_state_id = data.current_state_id;
				}
		  });
		});
	};            
	$scope.Restart = function(name) {
		$http.get('services.php?RestartService='+name).success(function(data){
			angular.forEach($scope.services, function(service, key) {
				if (service.name == name) {
					service.current_state_msg = $scope.MapServiceMsg(data.current_state_id);
	  			service.current_state_id = data.current_state_id;
				}
		  });
		});
	}
});
<?php
} elseif (isset($_REQUEST['GetServices'])) {
	foreach($service_array as $service) {
			$serviceStatusObj=win32_query_service_status($service);
			$state_msg = "";
			$serv[] = array("name" => $service,"current_state_id" => $serviceStatusObj['CurrentState'], "current_state_msg" => $state_msg);
	}
	echo json_encode($serv);
} elseif (isset($_REQUEST['StartService'])) {
	$name = $_REQUEST['StartService'];
	if (!in_array($name, $service_array)) die(); 
	flush();
	$result=win32_start_service($name);
	flush();
	// Sleeping and waiting for service to start for maximum 50 seconds
	$count=0;
	do
	{
		flush();
		sleep(1);
		$count=$count+1;
		if ($count==10) {win32_start_service($name);} //reissue stop command after 10 seconds
		if ($count==20) {win32_start_service($name);} //reissue stop command after 20 seconds
		$serviceStatObj = win32_query_service_status($name);
		$laststate = $serviceStatObj['CurrentState'];
	}
	while (($laststate!=4) and ($count<50));
	if ($laststate!=4)
	{
		//Echo "ERROR: Service '".$name."' did not start, sending just one more start command";
	  $result=win32_start_service($name); //give it one last try...
	}
	$serviceStatObj=win32_query_service_status($name);
	$statusId = $serviceStatObj['CurrentState'];
	$statusMsg = "";
	echo json_encode(array("name" => $name,"current_state_id" => $statusId, "current_state_msg" => $statusMsg));				
} elseif (isset($_REQUEST['StopService'])) {
	$name = $_REQUEST['StopService'];
	if (!in_array($name, $service_array)) die();
	flush();
	$result=win32_stop_service($name);
	flush();
	// Sleeping and waiting for service to start for maximum 50 seconds
	$count=0;
	do
	{
		flush();
		sleep(1);
		$count=$count+1;
		if ($count==10) {win32_stop_service($name);} //reissue stop command after 10 seconds
		if ($count==20) {win32_stop_service($name);} //reissue stop command after 20 seconds
		$serviceStatObj = win32_query_service_status($name);
		$laststate = $serviceStatObj['CurrentState'];
	}
	while (($laststate!=1) and ($count<50));
	$serviceStatObj=win32_query_service_status($name);
	$statusId = $serviceStatObj['CurrentState'];
	$statusMsg = "";
	echo json_encode(array("name" => $name,"current_state_id" => $statusId, "current_state_msg" => $statusMsg));
} elseif (isset($_REQUEST['RestartService'])) {
	$name = $_REQUEST['RestartService'];
  if (!in_array($name, $service_array)) die();
	flush();
  $result=win32_stop_service($name);
  // Sleeping and waiting for service to stop for maximum 50 seconds
  $count=0;
	do
	{
	flush();
	sleep(1);
	$count=$count+1;
	if ($count==10) {win32_stop_service($name);} //reissue stop command after 10 seconds
	if ($count==20) {win32_stop_service($name);} //reissue stop command after 20 seconds
	$laststate=win32_query_service_status($name);
	}
	while (($laststate!=1) and ($count<50));
	flush();
	$result=win32_start_service($name);
	flush();
	// Sleeping and waiting for service to start for maximum 50 seconds
	$count=0;
	do
	{
	flush();
	sleep(1);
	$count=$count+1;
	if ($count==10) {win32_start_service($name);} //reissue stop command after 10 seconds
	if ($count==20) {win32_start_service($name);} //reissue stop command after 20 seconds
	$laststate=Status($name);
	}
	while (($laststate!=4) and ($count<50));
	if ($laststate!=4)
	{
	$result=win32_start_service($name); //give it one last try...
	}
	$serviceStatObj=win32_query_service_status($name);
	$statusId = $serviceStatObj['CurrentState'];
	$statusMsg = "";
	echo json_encode(array("name" => $name,"current_state_id" => $statusId, "current_state_msg" => $statusMsg));			
} ?>