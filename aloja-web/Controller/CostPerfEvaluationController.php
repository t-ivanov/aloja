<?php

namespace alojaweb\Controller;

use alojaweb\inc\HighCharts;
use alojaweb\inc\Utils;
use alojaweb\inc\DBUtils;

class CostPerfEvaluationController extends AbstractController
{
	private $whereClause;

	private $clusterCosts;

	//Need to get container since overwritting parent constructor
	public function __construct($container) {
		parent::__construct($container);

		//All this screens are using this custom filters
		$this->buildFilters(array('bench' =>
			array('default' => array('terasort'),
				'type' => 'selectOne', 'label' => 'Benchmark:')));
		$this->whereClause = $this->filters->getWhereClause();
		$this->clusterCosts = Utils::generateCostsFilters($this->container->getDBUtils());
	}
	
    public function costPerfEvaluationAction()
    {
        $filter_execs = DBUtils::getFilterExecs();
        $dbUtils = $this->container->getDBUtils();
        
        try {
            /*
             * 1. Get execs and cluster associated costs
             * 2. For each exec calculate cost, exe_time/3600 * (cost_cluster + clust_remote|ssd|ib|eth)
             * 3. Calculate max and minimum costs
             * 4. calculate max and minimum exe times
             * 5. Normalize costs and exe times
             * 6. Print results
             */

            $minCost = -1;
            $maxCost = 0;
            $minExeTime = -1;
            $maxExeTime = 0;

            $execs = "SELECT e.*, c.* FROM aloja2.execs e JOIN aloja2.clusters c USING (id_cluster) WHERE 1 $filter_execs $this->whereClause ORDER BY rand() LIMIT 500";

            $execs = $dbUtils->get_rows($execs);
            if(!$execs)
                throw new \Exception("No results for query!");

            foreach($execs as &$exec) {
                $exec['cost_std'] = Utils::getExecutionCost($exec, $this->clusterCosts);

                if($exec['cost_std'] > $maxCost)
                    $maxCost = $exec['cost_std'];
                if($exec['cost_std'] < $minCost || $minCost == -1)
                    $minCost = $exec['cost_std'];

                if($exec['exe_time']<$minExeTime || $minExeTime == -1)
                    $minExeTime = $exec['exe_time'];
                if($exec['exe_time']>$maxExeTime)
                    $maxExeTime = $exec['exe_time'];
            }
        } catch (\Exception $e) {
            $this->container->getTwig()->addGlobal('message', $e->getMessage() . "\n");
        }

//         (exe_time - $min_exe_time)/($max_exe_time - $min_exe_time) exe_time_std,
//         ($cost_per_run - $min_cost_per_run)/($max_cost_per_run - $min_cost_per_run) cost_std,

        $seriesData = '';
        foreach ($execs as $exec) {
        	$exeTimeStd = 0.01;
        	$costTimeStd = 0.01;
        	if(count($execs) > 1) {
        		$exeTimeStd = ($exec['exe_time'] - $minExeTime)/($maxExeTime - $minExeTime);
        		$costTimeStd = ($exec['cost_std'] - $minCost)/($maxCost - $minCost);
        		
        		if($costTimeStd <= 0.01) $costTimeStd = 0.01;
        		if($exeTimeStd <= 0.01) $exeTimeStd = 0.01;
        	}

            $seriesData .= "{
            name: '" . $exec['exec'] . "',
                data: [[" . round($exeTimeStd, 3) . ", " . round($costTimeStd, 3) . "]], idexec: ${exec['id_exec']}},";
        }

        $clusters = $dbUtils->get_rows("SELECT * FROM aloja2.clusters WHERE id_cluster IN (SELECT DISTINCT id_cluster FROM aloja2.execs e WHERE 1 $filter_execs);");

        return $this->render('costPerfEvaluationViews/perf_by_cost.html.twig', array(
            'selected' => 'Cost Evaluation',
            'highcharts_js' => HighCharts::getHeader(),
			'clusterCosts' => $this->clusterCosts,
            'seriesData' => $seriesData,
            'title' => 'Normalized Cost by Performance Evaluation of Hadoop Executions',
            'clusters' => $clusters,
            'select_multiple_benchs' => false
        ));
    }

    public function clusterCostEffectivenessAction()
    {
        $db = $this->container->getDBUtils ();

        $data = array();

        $filter_execs = DBUtils::getFilterExecs();

        $query = "SELECT t.scount as count, e.*, c.* from execs e JOIN aloja2.clusters c USING (id_cluster)
        		INNER JOIN (SELECT count(*) as scount, MIN(exe_time) minexe FROM aloja2.execs JOIN aloja2.clusters USING(id_cluster)
        					 WHERE  1 $this->whereClause GROUP BY name,net,disk ORDER BY name ASC)
        		t ON e.exe_time = t.minexe WHERE 1 $filter_execs $this->whereClause GROUP BY c.name,e.net,e.disk ORDER BY c.name ASC;";
        
    	try {
    		$rows = $db->get_rows($query);
    		$minCost = -1;
    		$minCostKey = 0;
    		$sumCount = 0;
    		$previousCluster = "none";
    		$bestExecs = array();
    		foreach($rows as $key => &$row) {
    			$cost = Utils::getExecutionCost($row, $this->clusterCosts);
    			$row['cost_std'] = $cost;
    			if($previousCluster != "none" && $previousCluster != $row['name']) {
    				$min = $rows[$minCostKey];
    				array_push($bestExecs,$min);
    				$clusterDesc = "${min['datanodes']} datanodes,  ".round($min['vm_RAM'],0)." GB memory, ${min['vm_OS']}, ${min['provider']} ${min['type']}";
    				$set = array(round($min['exe_time'],0), round($minCost,2), $sumCount);
    				array_push($data, array('data' => array($set), 'name' => $min['name'], 'clusterdesc' => $clusterDesc, 'counts' => $sumCount));
    				$previousCluster = $row['name'];
    				$minCost = -1;
    				$sumCount = 0;
    			} else if($previousCluster == "none")
    				$previousCluster = $row['name'];
    			
    			if($minCost == -1 || $cost < $minCost) {
    				$minCost = $cost;
    				$minCostKey = $key;
    			}
    			
    			$sumCount += $row['count'];
    		}
    		$min = $rows[$minCostKey];
    		array_push($bestExecs,$min);
    		$clusterDesc = "${min['datanodes']} datanodes,  ".round($min['vm_RAM'],0)." GB memory, ${min['vm_OS']}, ${min['provider']} ${min['type']}";
    		$set = array(round($min['exe_time'],0), round($minCost,2), $sumCount);
    		array_push($data, array('data' => array($set), 'name' => $min['name'], 'clusterdesc' => $clusterDesc, 'counts' => $sumCount));
    		
    		//This is to order the cluster by cost-effectiveness (ascending)
    		//This way the labels in the cart are ordered
    		usort($data,function($a, $b) {
                $costA = $a['data'][0][1];
                $costB = $b['data'][0][1];
                //$costA = $a['data'][0][0] * $a['data'][0][1];
    			//$costB = $b['data'][0][0] * $b['data'][0][1];
    			return $costA >= $costB;
    		});
    		
    		//Sorting clusters by size
    		usort($bestExecs, function($a,$b) {
                return $a['cost_std'] > $b['cost_std'];
    			//return ($a['cost_std']*$a['exe_time']) > ($b['cost_std']*$b['exe_time']);
    		});

			$clusters = $db->get_rows("SELECT * FROM aloja2.clusters WHERE id_cluster IN (SELECT DISTINCT id_cluster FROM aloja2.execs e WHERE 1 $filter_execs);");

		} catch (\Exception $e) {
    		$this->container->getTwig()->addGlobal('message',$e->getMessage()."\n");
    	}

		return $this->render('costPerfEvaluationViews/clustercosteffectiveness.html.twig', array(
    			'series' => json_encode($data),
    			'select_multiple_benchs' => false,
                'bestExecs' => $bestExecs,
				'clusterCosts' => $this->clusterCosts,
				'clusters' => $clusters
    		));
    }
    
    public function costPerfClusterEvaluationAction()
    {
    	$filter_execs = DBUtils::getFilterExecs();
    	$dbUtils = $this->container->getDBUtils();
		
    	try {
    		/*
    		 * 1. Get execs and cluster associated costs
    		* 2. For each exec calculate cost, exe_time/3600 * (cost_cluster + clust_remote|ssd|ib|eth)
    		* 3. Calculate max and minimum costs
    		* 4. calculate max and minimum exe times
    		* 5. Normalize costs and exe times
    		* 6. Print results
    		*/
    
    		$minCost = -1;
    		$maxCost = 0;
    		$minExeTime = -1;
    		$maxExeTime = 0;
    		$sumCount = 0;
    
    		$execs = "SELECT e.exe_time,e.net,e.disk,e.bench,e.bench_type,e.maps,e.iosf,e.replication,e.iofilebuf,e.comp,e.blk_size,e.hadoop_version,e.exec, c.name as clustername,c.* 
    		  FROM aloja2.execs e JOIN aloja2.clusters c USING (id_cluster)
      		  INNER JOIN (SELECT MIN(exe_time) minexe FROM aloja2.execs e JOIN aloja2.clusters c USING(id_cluster)
        					 WHERE  1 $filter_execs $this->whereClause GROUP BY name,net,disk ORDER BY name ASC)
        		t ON e.exe_time = t.minexe  WHERE 1 $filter_execs $this->whereClause
    		  GROUP BY c.name,e.net,e.disk ORDER BY c.name ASC;";
    
    		$execs = $dbUtils->get_rows($execs);
    		if(!$execs)
    			throw new \Exception("No results for query!");
    
    		foreach($execs as &$exec) {
				$exec['cost_std'] = Utils::getExecutionCost($exec, $this->clusterCosts);
    
    			if($exec['cost_std'] > $maxCost)
    				$maxCost = $exec['cost_std'];
    			if($exec['cost_std'] < $minCost || $minCost == -1)
    				$minCost = $exec['cost_std'];
    
    			if($exec['exe_time']<$minExeTime || $minExeTime == -1)
    				$minExeTime = $exec['exe_time'];
    			if($exec['exe_time']>$maxExeTime)
    				$maxExeTime = $exec['exe_time'];
    		}
    	} catch (\Exception $e) {
    		$this->container->getTwig()->addGlobal('message', $e->getMessage() . "\n");
    	}
    
    	//         (exe_time - $min_exe_time)/($max_exe_time - $min_exe_time) exe_time_std,
    	//         ($cost_per_run - $min_cost_per_run)/($max_cost_per_run - $min_cost_per_run) cost_std,
    
    	$seriesData = '';
    	foreach ($execs as $exec) {
    		$exeTimeStd = 0.01;
    		$costTimeStd = 0.01;
    		if(count($execs) > 1) {
	    		$exeTimeStd = ($exec['exe_time'] - $minExeTime)/($maxExeTime - $minExeTime);
	    		$costTimeStd = ($exec['cost_std'] - $minCost)/($maxCost - $minCost);
	    		if($costTimeStd <= 0.01) $costTimeStd = 0.01;
	    		if($exeTimeStd <= 0.01) $exeTimeStd = 0.01;
    		}
    
    		$seriesData .= "{
            name: '" . $exec['exec'] . "',
                data: [[" . round($exeTimeStd, 3) . ", " . round($costTimeStd, 3) . ", ". round($costTimeStd*$exeTimeStd, 3) ."]]
        },";
    	}
    
    	$clusters = $dbUtils->get_rows("SELECT * FROM aloja2.clusters c WHERE id_cluster IN (SELECT DISTINCT(id_cluster) FROM aloja2.execs e WHERE 1 $filter_execs);");
    
    	//Sorting clusters by size
    	usort($execs, function($a,$b) {
    		return ($a['cost_std']) > ($b['cost_std']);
    	});
    	return $this->render('costPerfEvaluationViews/perf_by_cost_cluster.html.twig', array(
    			'highcharts_js' => HighCharts::getHeader(),
				'clusterCosts' => $this->clusterCosts,
    			'seriesData' => $seriesData,
    			'execs' => $execs,
    			'title' => 'Normalized Cost by Performance Evaluation of Hadoop Executions',
    			'clusters' => $clusters,
    	));
    }
    
    public function BestCostPerfClusterEvaluationAction()
    {
    	$filter_execs = DBUtils::getFilterExecs();
    	$dbUtils = $this->container->getDBUtils();

    	try {
    		

    		/*
    		 * 1. Get execs and cluster associated costs
    		* 2. For each exec calculate cost, exe_time/3600 * (cost_cluster + clust_remote|ssd|ib|eth)
    		* 3. Calculate max and minimum costs
    		* 4. calculate max and minimum exe times
    		* 5. Normalize costs and exe times
    		* 6. Print results
    		*/
    
    		$minCost = -1;
    		$maxCost = 0;
    		$minExeTime = -1;
    		$maxExeTime = 0;

    		$execs = "SELECT t.scount as count, e.exe_time,e.net,e.disk,e.bench,e.bench_type,e.maps,e.iosf,e.replication,e.iofilebuf,e.comp,e.blk_size,e.hadoop_version,e.exec, c.name as clustername,c.* 
    		  FROM aloja2.execs e JOIN aloja2.clusters c USING (id_cluster)
      		  INNER JOIN (SELECT count(*) as scount, MIN(exe_time) minexe FROM aloja2.execs e JOIN aloja2.clusters c USING(id_cluster)
        					 WHERE  1 $filter_execs $this->whereClause GROUP BY name,net,disk ORDER BY name ASC)
        		t ON e.exe_time = t.minexe  WHERE 1 $filter_execs $this->whereClause
    		  GROUP BY c.name,e.net,e.disk ORDER BY c.name ASC;";
    
    		$execs = $dbUtils->get_rows($execs);
    		if(!$execs)
    			throw new \Exception("No results for query!");
    
    		$minCostKey = 0;
    		$tmpMinCost = -1;
    		$previousCluster = "none";
    		$bestExecs = array();
    		$sumCount = 0;
    		foreach($execs as $key => &$exec) {
    			if($previousCluster != "none" && $previousCluster != $exec['name']) {
    				$previousCluster = $exec['name'];
    				$tmpMinCost = -1;
    				
    				if($execs[$minCostKey]['cost_std'] > $maxCost)
    					$maxCost = $execs[$minCostKey]['cost_std'];
    				if($execs[$minCostKey]['cost_std'] < $minCost || $minCost == -1)
    					$minCost = $execs[$minCostKey]['cost_std'];
    				
    				if($execs[$minCostKey]['exe_time']<$minExeTime || $minExeTime == -1)
    					$minExeTime = $execs[$minCostKey]['exe_time'];
    				if($execs[$minCostKey]['exe_time']>$maxExeTime)
    					$maxExeTime = $execs[$minCostKey]['exe_time'];
    				
    				$execs[$minCostKey]['countexecs'] = $sumCount;
    				
    				array_push($bestExecs, $execs[$minCostKey]);
    				$sumCount = 0;
    			} else if($previousCluster == "none")
    				$previousCluster = $exec['name'];
    
    			$exec['cost_std'] = Utils::getExecutionCost($exec, $this->clusterCosts);
    			
    			if($tmpMinCost == -1 || $exec['cost_std'] < $tmpMinCost) {
    				$tmpMinCost = $exec['cost_std'];
    				$minCostKey = $key;
    			}
    			
    			$sumCount += $exec['count'];
    		}    		
    		if($execs[$minCostKey]['cost_std'] > $maxCost)
    			$maxCost = $execs[$minCostKey]['cost_std'];
    		if($execs[$minCostKey]['cost_std'] < $minCost || $minCost == -1)
    			$minCost = $execs[$minCostKey]['cost_std'];
    		
    		if($execs[$minCostKey]['exe_time']<$minExeTime || $minExeTime == -1)
    			$minExeTime = $execs[$minCostKey]['exe_time'];
    		if($execs[$minCostKey]['exe_time']>$maxExeTime)
    			$maxExeTime = $execs[$minCostKey]['exe_time'];
    		
    		$execs[$minCostKey]['countexecs'] = $sumCount;
    		array_push($bestExecs, $execs[$minCostKey]);
    	} catch (\Exception $e) {
    		$this->container->getTwig()->addGlobal('message', $e->getMessage() . "\n");
    	}
    
    	//         (exe_time - $min_exe_time)/($max_exe_time - $min_exe_time) exe_time_std,
    	//         ($cost_per_run - $min_cost_per_run)/($max_cost_per_run - $min_cost_per_run) cost_std,
    
    	$seriesData = '';
    	foreach ($bestExecs as $exec) {
    		$exeTimeStd = 0.01;
    		$costTimeStd = 0.01;
    		if(count($bestExecs) > 1) {
	    		$exeTimeStd = ($exec['exe_time'] - $minExeTime)/($maxExeTime - $minExeTime);
	    		$costTimeStd = ($exec['cost_std'] - $minCost)/($maxCost - $minCost);
	    		if($costTimeStd <= 0.01) $costTimeStd = 0.01;
	    		if($exeTimeStd <= 0.01) $exeTimeStd = 0.01;
    		}
    
    		$clusterDesc = "${exec['datanodes']} datanodes,  ".round($exec['vm_RAM'],0)." GB memory, ${exec['vm_OS']}, ${exec['provider']} ${exec['type']}";
    		$seriesData .= "{
            name: '" . $exec['name'] . "',
                data: [[" . round($exeTimeStd, 3) . ", " . round($costTimeStd, 3) . ", ". $exec['countexecs'] ."]],
            clusterdesc: '$clusterDesc', countExecs: '${exec['countexecs']}'
        },";
    	}
    
    	$clusters = $dbUtils->get_rows("SELECT * FROM aloja2.clusters c WHERE id_cluster IN (SELECT DISTINCT(id_cluster) FROM aloja2.execs e WHERE 1 $filter_execs);");

    	//Sorting clusters by size
    	usort($bestExecs, function($a,$b) {
    		return ($a['cost_std']) > ($b['cost_std']);
    	});
    	
    	return $this->render('costPerfEvaluationViews/best_perf_by_cost_cluster.html.twig', array(
    			'highcharts_js' => HighCharts::getHeader(),
    			'clusterCosts' => $this->clusterCosts,
    			'seriesData' => $seriesData,
    			'bestExecs' => $bestExecs,
    			'clusters' => $clusters,
    	));
    }

    public function nodesEvaluationAction()
    {
        $dbUtils = $this->container->getDBUtils();
		
        try {
            $filter_execs = DBUtils::getFilterExecs();

            $execs = $dbUtils->get_rows("SELECT c.datanodes,e.exec_type,c.vm_OS,c.vm_size,(e.exe_time * (c.cost_hour/3600)) as cost,e.*,c.* FROM aloja2.execs e JOIN aloja2.clusters c USING (id_cluster) INNER JOIN ( SELECT c2.datanodes,e2.exec_type,c2.vm_OS,c2.vm_size as vmsize,MIN(e2.exe_time) as minexe from execs e2 JOIN aloja2.clusters c2 USING (id_cluster) WHERE 1 $this->whereClause GROUP BY c2.datanodes,e2.exec_type,c2.vm_OS,c2.vm_size ) t ON t.minexe = e.exe_time AND t.datanodes = c.datanodes AND t.vmsize = c.vm_size WHERE 1 $filter_execs  GROUP BY c.datanodes,e.exec_type,c.vm_OS,c.vm_size ORDER BY c.datanodes ASC,c.vm_OS,c.vm_size DESC;");

            $vmSizes = array();
            $categories = array();
            $dataNodes = array();
            $vmOS = array();
            $execTypes = array();
            foreach ($execs as &$exec) {
                if (!isset($dataNodes[$exec['datanodes']])) {
                    $dataNodes[$exec['datanodes']] = 1;
                    $categories[] = $exec['datanodes'];
                }
                if(!isset($vmOS[$exec['vm_OS']]))
                    $vmOS[$exec['vm_OS']] = 1;
                if(!isset($execTypes[$exec['exec_type']]))
                    $execTypes[$exec['exec_type']] = 1;

                $vmSizes[$exec['vm_size']][$exec['exec_type']][$exec['vm_OS']][$exec['datanodes']] = array(round($exec['exe_time'],2), round($exec['cost'],2));
            }

            $i = 0;
            $seriesColors = array('#7cb5ec', '#434348', '#90ed7d', '#f7a35c', '#8085e9',
                '#f15c80', '#e4d354', '#2b908f', '#f45b5b', '#91e8e1');
            $series = array();
            foreach($vmSizes as $vmSize => $value) {
                foreach($execTypes as $execType => $typevalue) {
                    foreach ($vmOS as $OS => $osvalue) {
                        if (isset($vmSizes[$vmSize][$execType][$OS])) {
                            if ($i == sizeof($seriesColors))
                                $i = 0;
                            $costSeries = array('name' => "$vmSize $execType $OS Run cost", 'type' => 'spline', 'dashStyle' => 'longdash', 'yAxis' => 0, 'data' => array(), 'tooltip' => array('valueSuffix' => ' US$'), 'color' => $seriesColors[$i]);
                            $timeSeries = array('name' => "$vmSize $execType $OS Run execution time", 'type' => 'spline', 'yAxis' => 1, 'data' => array(), 'tooltip' => array('valueSuffix' => ' s'), 'color' => $seriesColors[$i++]);
                            foreach ($dataNodes as $datanodes => $dvalue) {
                                if (!isset($value[$execType][$OS][$datanodes])) {
                                    $costSeries['data'][] = "null";
                                    $timeSeries['data'][] = "null";
                                } else {
                                    $costSeries['data'][] = $value[$execType][$OS][$datanodes][1];
                                    $timeSeries['data'][] = $value[$execType][$OS][$datanodes][0];
                                }
                            }
                            $series[] = $timeSeries;
                            $series[] = $costSeries;
                        }
                    }
                }
            }
        } catch(\Exception $e) {
            $this->container->getTwig ()->addGlobal ( 'message', $e->getMessage () . "\n" );
        }

        return $this->render('costPerfEvaluationViews/nodes_evaluation.html.twig', array(
            'highcharts_js' => HighCharts::getHeader(),
            'categories' => json_encode($categories),
            'seriesData' => str_replace('"null"','null',json_encode($series)),
            'datanodess' => $datanodes,
        ));
    }
}
