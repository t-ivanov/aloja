<?php

namespace alojaweb\Controller;

use alojaweb\inc\HighCharts;
use alojaweb\inc\Utils;
use alojaweb\inc\DBUtils;
use alojaweb\inc\MLUtils;

class MLFindAttributesController extends AbstractController
{
	public function mlfindattributesAction()
	{
		$instance = $instances =  $message = $tree_descriptor = $model_html = $config = '';
		$possible_models = $possible_models_id = $other_models = array();
		$must_wait = 'NO';
		try
		{
			$dbml = new \PDO($this->container->get('config')['db_conn_chain'], $this->container->get('config')['mysql_user'], $this->container->get('config')['mysql_pwd']);
			$dbml->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
			$dbml->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

		    	$db = $this->container->getDBUtils();

			if (array_key_exists('dump',$_GET))
			{
				$dump = $_GET["dump"];
				unset($_GET["dump"]);
			}

			if (array_key_exists('pass',$_GET))
			{
				$pass = $_GET["pass"];
				unset($_GET["pass"]);
			}

			$this->buildFilters(array('current_model' => array(
				'type' => 'selectOne',
				'default' => null,
				'label' => 'Model to use: ',
				'generateChoices' => function() {
					return array();
				},
				'parseFunction' => function() {
					$choice = isset($_GET['current_model']) ? $_GET['current_model'] : array("");
					return array('whereClause' => '', 'currentChoice' => $choice);
				},
				'filterGroup' => 'MLearning'
			), 'unseen' => array(
				'type' => 'checkbox',
				'default' => 1,
				'label' => 'Predict with unseen atributes &#9888;',
				'parseFunction' => function() {
					$choice = (isset($_GET['unseen']) && !isset($_GET['unseen'])) ? 0 : 1;
					return array('whereClause' => '', 'currentChoice' => $choice);
				},
				'filterGroup' => 'MLearning'
			), 'minexetime' => array(
				'default' => 0
			), 'valid' => array(
				'default' => 0
			), 'filter' => array(
				'default' => 0
			), 'prepares' => array(
				'default' => 1
			)
			));
			$this->buildFilterGroups(array('MLearning' => array('label' => 'Machine Learning', 'tabOpenDefault' => true, 'filters' => array('current_model','unseen'))));
			$where_configs = $this->filters->getWhereClause();

			$param_names = array('bench','net','disk','maps','iosf','replication','iofilebuf','comp','blk_size','id_cluster','datanodes','vm_OS','vm_cores','vm_RAM','provider','vm_size','type','bench_type','hadoop_version'); // Order is important
			$params = $this->filters->getFiltersSelectedChoices($param_names);
			foreach ($param_names as $p) if (!is_null($params[$p]) && is_array($params[$p])) sort($params[$p]);

			$learnParams = $this->filters->getFiltersSelectedChoices(array('current_model','unseen'));
			$param_current_model = $learnParams['current_model'];
			$unseen = ($learnParams['unseen']) ? true : false;

			// FIXME PATCH FOR PARAM LIBRARIES WITHOUT LEGACY
			$where_configs = str_replace("AND .","AND ",$where_configs);

			$jsonData = $jsonHeader = "[]";
			$mae = $rae = 0;

			// compose instance
			$model_info = MLUtils::generateModelInfo($this->filters,$param_names, $params, $unseen);
			$instance = MLUtils::generateSimpleInstance($this->filters,$param_names, $params, $unseen);
			$instances = MLUtils::generateInstances($this->filters,$param_names, $params, $unseen,$db);

			// Model for filling
			MLUtils::findMatchingModels($model_info, $possible_models, $possible_models_id, $dbml);

			$current_model = '';
			if (!is_null($possible_models_id) && in_array($param_current_model,$possible_models_id)) $current_model = $param_current_model;

			// Other models for filling
			$where_models = '';
			if (!empty($possible_models_id))
			{
				$where_models = " WHERE id_learner NOT IN ('".implode("','",$possible_models_id)."')";
			}
			$result = $dbml->query("SELECT id_learner FROM aloja_ml.learners".$where_models);
			foreach ($result as $row) $other_models[] = $row['id_learner'];

			if (!empty($possible_models_id) || $current_model != "")
			{
				$result = $dbml->query("SELECT id_learner, model, algorithm, CASE WHEN `id_learner` IN ('".implode("','",$possible_models_id)."') THEN 'COMPATIBLE' ELSE 'NOT MATCHED' END AS compatible FROM aloja_ml.learners");
				foreach ($result as $row) $model_html = $model_html."<li>".$row['id_learner']." => ".$row['algorithm']." : ".$row['compatible']." : ".$row['model']."</li>";

				if ($current_model == "")
				{
					$query = "SELECT AVG(ABS(exe_time - pred_time)) AS MAE, AVG(ABS(exe_time - pred_time)/exe_time) AS RAE, p.id_learner FROM aloja_ml.predictions p, aloja_ml.learners l WHERE l.id_learner = p.id_learner AND p.id_learner IN ('".implode("','",$possible_models_id)."') AND predict_code > 0 ORDER BY MAE LIMIT 1";
					$result = $dbml->query($query);
					$row = $result->fetch();	
					$current_model = $row['id_learner'];
				}
				$config = $instance.'-'.$current_model.'-'.(($unseen)?'U':'R');

				$is_cached_mysql = $dbml->query("SELECT count(*) as total FROM aloja_ml.trees WHERE id_findattrs = '".md5($config)."'");
				$tmp_result = $is_cached_mysql->fetch();
				$is_cached = ($tmp_result['total'] > 0);

				$tmp_file = md5($config).'.tmp';

				$in_process = file_exists(getcwd().'/cache/query/'.md5($config).'.lock');
				$finished_process = $in_process && ((int)shell_exec('ls '.getcwd().'/cache/query/'.md5($config).'-*.lock | wc -w ') == count($instances));

				if (!$in_process && !$finished_process && !$is_cached)
				{
					// Retrieve file model from DB
					$query = "SELECT file FROM aloja_ml.model_storage WHERE id_hash='".$current_model."' AND type='learner';";
					$result = $dbml->query($query);
					$row = $result->fetch();
					$content = $row['file'];

					$filemodel = getcwd().'/cache/query/'.$current_model.'-object.rds';
					$fp = fopen($filemodel, 'w');
					fwrite($fp,$content);
					fclose($fp);

					// Run the predictor
					exec('cd '.getcwd().'/cache/query ; touch '.md5($config).'.lock ; rm -f '.$tmp_file);
					$count = 1;
					foreach ($instances as $inst)
					{
						exec(getcwd().'/resources/queue -d -c "cd '.getcwd().'/cache/query ; ../../resources/aloja_cli.r -m aloja_predict_instance -l '.$current_model.' -p inst_predict=\''.$inst.'\' -v | grep -v \'Prediction\' >>'.$tmp_file.' 2>/dev/null; touch '.md5($config).'-'.($count++).'.lock" >/dev/null 2>&1 &');
					}
				}
				$finished_process = ((int)shell_exec('ls '.getcwd().'/cache/query/'.md5($config).'-*.lock | wc -w ') == count($instances));

				if ($finished_process && !$is_cached)
				{
					// Read results and dump to DB
					$i = 0;
					$token = 0;
					$token_i = 0;
					$query = "INSERT IGNORE INTO aloja_ml.predictions (id_exec,exe_time,bench,net,disk,maps,iosf,replication,iofilebuf,comp,blk_size,id_cluster,datanodes,vm_OS,vm_cores,vm_RAM,provider,vm_size,type,bench_type,hadoop_version,pred_time,id_learner,instance,predict_code) VALUES ";
					if (($handle = fopen(getcwd().'/cache/query/'.$tmp_file, "r")) !== FALSE)
					{
						while (($line = fgets($handle, 1000)) !== FALSE && $i < 1000) // FIXME - Mysql install current limitation
						{
							if ($line=='') break;

							// Fetch Real Value
							$inst_aux = preg_split("/\s+/", $line);
							$query_var = "SELECT AVG(exe_time) as AVG, id_exec, outlier FROM aloja_ml.predictions WHERE instance = '".$inst_aux[1]."' AND predict_code > 0";
							$result = $dbml->query($query_var);
							$row = $result->fetch();

							$realexecval = (is_null($row['AVG']) || $row['outlier'] == 2)?0:$row['AVG'];
							$realid_exec = (is_null($row['id_exec']) || $row['outlier'] == 2)?0:$row['id_exec'];

							$query_var = "SELECT count(*) as num FROM aloja_ml.predictions WHERE instance = '".$inst_aux[1]."' AND id_learner = '".$current_model."'";
                                                        $result = $dbml->query($query_var);
                                                        $row = $result->fetch();

                                                        // Insert instance values
                                                        if ($row['num'] == 0)
                                                        {

								$token_i = 1;
								$selected_instance = preg_replace('/,Cmp(\d+),/',',${1},',$inst_aux[1]);
								$selected_instance = preg_replace('/,Cl(\d+),/',',${1},',$selected_instance);
								if ($token > 0) { $query = $query.","; } $token = 1;
								$query = $query."('".$realid_exec."','".$realexecval."','".str_replace(",","','",$selected_instance)."','".$inst_aux[2]."','".$current_model."','".$inst_aux[1]."','0') ";
							}

							$i++;

							if ($i % 100 == 0 && $token_i > 0)
							{
								if ($dbml->query($query) === FALSE) throw new \Exception('Error when saving into DB');
								$query = "INSERT IGNORE INTO aloja_ml.predictions (id_exec,exe_time,bench,net,disk,maps,iosf,replication,iofilebuf,comp,blk_size,id_cluster,datanodes,vm_OS,vm_cores,vm_RAM,provider,vm_size,type,bench_type,hadoop_version,pred_time,id_learner,instance,predict_code) VALUES ";
								$token = 0;
								$token_i = 0;
							}
						}
						if ($token_i > 0)
						{
							if ($dbml->query($query) === FALSE) throw new \Exception('Error when saving into DB');
						}

						// Descriptive Tree
						$tree_descriptor = shell_exec(getcwd().'/resources/aloja_cli.r -m aloja_representative_tree -p method=ordered:dump_file="'.getcwd().'/cache/query/'.$tmp_file.'":output="html" -v 2> /dev/null');
						$tree_descriptor = substr($tree_descriptor, 5, -2);
						$query = "INSERT INTO aloja_ml.trees (id_findattrs,id_learner,instance,model,tree_code) VALUES ('".md5($config)."','".$current_model."','".$instance."','".$model_info."','".$tree_descriptor."')";

						if ($dbml->query($query) === FALSE) throw new \Exception('Error when saving tree into DB');

						// remove remaining locks
						shell_exec('rm -f '.getcwd().'/cache/query/'.md5($config).'*.lock'); 

						// Remove temporal files
						$output = shell_exec('rm -f '.getcwd().'/cache/query/'.md5($config).'.tmp');

						$is_cached = true;
					}
					fclose($handle);
				}

				if (!$is_cached)
				{
					$jsonData = $jsonHeader = $jsonColumns = $jsonColor = '[]';
					$must_wait = 'YES';
					if (isset($dump)) { $dbml = null; echo "1"; exit(0); }
					if (isset($pass)) { $dbml = null; return "1"; }
				}
				else
				{
					if (isset($pass) && $pass == 2) { $dbml = null; return "2"; }

					// Fetch results and compose JSON
					$header = array('Benchmark','Net','Disk','Maps','IO.SFS','Rep','IO.FBuf','Comp','Blk.Size','Cluster','Datanodes','VM.OS','VM.Cores','VM.RAM','Provider','VM.Size','Type','Bench.Type','Version','Prediction','Observed');
					$jsonHeader = '[{title:""}';
					foreach ($header as $title) $jsonHeader = $jsonHeader.',{title:"'.$title.'"}';
					$jsonHeader = $jsonHeader.']';

					$query = "SELECT @i:=@i+1 as num, instance, AVG(pred_time) as pred_time, AVG(exe_time) as exe_time FROM aloja_ml.predictions, (SELECT @i:=0) d WHERE id_learner='".$current_model."' ".$where_configs." GROUP BY instance";
					$result = $dbml->query($query);
					$jsonData = '[';
					foreach ($result as $row)
					{
						if ($jsonData!='[') $jsonData = $jsonData.',';
						$jsonData = $jsonData."['".$row['num']."','".str_replace(",","','",$row['instance'])."','".$row['pred_time']."','".$row['exe_time']."']";
					}
					$jsonData = $jsonData.']';

					foreach (range(1,33) as $value) $jsonData = str_replace('Cmp'.$value,Utils::getCompressionName($value),$jsonData);

					// Fetch MAE & RAE values
					$query = "SELECT AVG(ABS(exe_time - pred_time)) AS MAE, AVG(ABS(exe_time - pred_time)/exe_time) AS RAE FROM aloja_ml.predictions WHERE id_learner='".md5($config)."' AND predict_code > 0";
					$result = $dbml->query($query);
					$row = $result->fetch();
					$mae = $row['MAE'];
					$rae = $row['RAE'];

					// Dump case
					if (isset($dump))
					{
						echo "ID".str_replace(array("[","]","{title:\"","\"}"),array('','',''),$jsonHeader)."\n";
						echo str_replace(array('],[','[[',']]'),array("\n",'',''),$jsonData);

						$dbml = null;
						exit(0);
					}
					if (isset($pass) && $pass == 1)
					{
						$retval = "ID".str_replace(array("[","]","{title:\"","\"}"),array('','',''),$jsonHeader)."\n";
						$retval .= str_replace(array('],[','[[',']]'),array("\n",'',''),$jsonData);

						$dbml = null;
						return $retval;
					}

					// Display Descriptive Tree
					$query = "SELECT tree_code FROM aloja_ml.trees WHERE id_findattrs = '".md5($config)."'";
					$result = $dbml->query($query);
					$row = $result->fetch();
					$tree_descriptor = $row['tree_code'];
				}			
			}
			else
			{
	 			$message = "There are no prediction models trained for such parameters. Train at least one model in 'ML Prediction' section.";
				$must_wait = 'NO';
				if (isset($dump)) { echo "-1"; exit(0); }
				if (isset($pass)) { return "-1"; }
			}
			$dbml = null;
		}
		catch(\Exception $e)
		{
			$this->container->getTwig ()->addGlobal ( 'message', $e->getMessage () . "\n" );

			$jsonData = $jsonHeader = "[]";
			$must_wait = 'NO';
			$mae = $rae = 0;

			$dbml = null;
			if (isset($pass)) { return "-2"; }
		}

		$return_params = array(
			'instance' => $instance,
			'jsonData' => $jsonData,
			'jsonHeader' => $jsonHeader,
			'models' => $model_html,
			'models_id' => $possible_models_id,
			'other_models_id' => $other_models,
			'current_model' => $current_model,
			'message' => $message,
			'mae' => $mae,
			'rae' => $rae,
			'must_wait' => $must_wait,
			'instance' => $instance,
			'instances' => implode("<br/>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;",$instances),
			'model_info' => $model_info,
			'id_findattr' => md5($config),
			'unseen' => $unseen,
			'tree_descriptor' => $tree_descriptor,
		);
		$this->filters->setCurrentChoices('current_model',array_merge($possible_models_id,array('---Other models---'),$other_models));
		return $this->render('mltemplate/mlfindattributes.html.twig', $return_params);
	}
}
?>
