#HADOOP SPECIFIC FUNCTIONS
source_file "$ALOJA_REPO_PATH/shell/common/common_java.sh"
set_java_requires

get_hadoop_config_folder() {
  local config_folder_name

  if [ "$HADOOP_CUSTOM_CONFIG" ] ; then
    config_folder_name="$HADOOP_CUSTOM_CONFIG"
  elif [ "$HADOOP_EXTRA_JARS" == "AOP4Hadoop" ] ; then
    config_folder_name="hadoop1_AOP_conf_template"
  elif [ "$(get_hadoop_major_version)" == "2" ]; then
    config_folder_name="hadoop2_conf_template"
  else
    config_folder_name="hadoop1_conf_template"
  fi

  echo -e "$config_folder_name"
}

set_hadoop_config_folder() {
  BENCH_CONFIG_FOLDERS="$BENCH_CONFIG_FOLDERS
$(get_hadoop_config_folder)"
}

# Sets the required files to download/copy
set_hadoop_requires() {
  if [ "$(get_hadoop_major_version)" == "2" ]; then
    BENCH_REQUIRED_FILES["$HADOOP_VERSION"]="http://archive.apache.org/dist/hadoop/core/$HADOOP_VERSION/$HADOOP_VERSION.tar.gz"
  else
    BENCH_REQUIRED_FILES["$HADOOP_VERSION"]="http://archive.apache.org/dist/hadoop/core/$HADOOP_VERSION/$HADOOP_VERSION-bin.tar.gz"
  fi

  if [ "$HADOOP_EXTRA_JARS" ] ; then
    BENCH_REQUIRED_FILES["HADOOP_EXTRA_JARS"]="$ALOJA_PUBLIC_HTTP/aplic2/tarballs/$HADOOP_EXTRA_JARS.tar.gz"
  fi

  #also set the config here
  set_hadoop_config_folder
}

# Helper to print a line with Hadoop requiered exports
get_hadoop_exports() {
  local to_export

  # For both versions
  to_export="$(get_java_exports)
export HADOOP_CONF_DIR='$HDD/conf';
export HADOOP_LOG_DIR='$HDD/logs';
export HADOOP_HOME='$(get_local_apps_path)/${HADOOP_VERSION}';
export HADOOP_OPTS='$HADOOP_OPTS';"

  # For v2 only
  if [ "$(get_hadoop_major_version)" == "2" ]; then
    to_export="$to_export
export HADOOP_YARN_HOME='$(get_local_apps_path)/${HADOOP_VERSION}';
YARN_LOG_DIR='$HDD/logs';"
  fi

  if [ "$HADOOP_EXTRA_JARS" ] ; then
    # Right now jar files are hard-coded
    to_export="$to_export
export HADOOP_USER_CLASSPATH_FIRST=true;
export HADOOP_CLASSPATH=$(get_local_apps_path)/$HADOOP_EXTRA_JARS/aspectjrt-1.6.5.jar:$(get_local_apps_path)/$HADOOP_EXTRA_JARS/AOP4Hadoop-hadoop-core-1.0.3.jar:\$HADOOP_CLASSPATH;"
  fi

  echo -e "$to_export\n"
}

# Function to return job specific config
# rr in the case of PaaS where we cannot change the server config
get_hadoop_job_config() {
  local job_config="$BENCH_EXTRA_CONFIG"

  # For v2 only
  if [ "$(get_hadoop_major_version)" == "2" ]; then
    job_config+=" -D mapreduce.job.maps='$MAX_MAPS'"
    job_config+=" -D mapreduce.job.reduces='$MAX_MAPS'"
  else
    job_config+=" -D mapred.map.tasks='$MAX_MAPS'"
    job_config+=" -D mapred.reduce.tasks='$MAX_MAPS'"
  fi

  echo -e "$job_config"
}


# Get the list of slaves
# TODO should be improved to include master node as worker node if necessary
# $1 list of nodes
# $2 master name
get_hadoop_slaves() {
  local all_nodes="$1"
  local master_name="$2"
  local only_slaves

  if [ "$all_nodes" ] && [ "$master_name" ] ; then
    only_slaves="$(echo -e "$all_nodes"|grep -v "$master_name")"
  else
    die "Empty list of nodes supplied"
  fi

  echo -e "$only_slaves"
}

# Sets a coma separeted list of disks for the hadoop conf file
#1 disk type $2 postfix $3 port prefix
get_hadoop_conf_dir() {
  local dir

  local disks="$(get_specified_disks "$1")"
  for disk_tmp in $disks ; do
    dir="$dir\,$disk_tmp/$(get_aloja_dir "$3")/$2"
  done

  if [ "$dir" ] ; then
    dir="${dir:2}" #remove leading \,
    echo -e "$dir"
  else
    die "Cannot get disk config for specified disk $1"
  fi
}


#old code moved here
# TODO cleanup
initialize_hadoop_vars() {

  [ ! "$HDD" ] && die "HDD var not set!"

  BENCH_HADOOP_DIR="$(get_local_apps_path)/$HADOOP_VERSION" #execution dir

  HADOOP_CONF_DIR="$HDD/conf"
  HADOOP_EXPORTS="$(get_hadoop_exports)"

#  if [ ! "$HADOOP_VERSION" ] ; then
#    if [ "$HADOOP_VERSION" == "hadoop1" ]; then
#      HADOOP_VERSION="hadoop-1.0.3"
#    elif [ "$HADOOP_VERSION" == "hadoop2" ] ; then
#      HADOOP_VERSION="hadoop-2.6.0"
#    fi
#  fi

  # Use instrumented version of Hadoop
  if [ "$INSTRUMENTATION" == "1" ] ; then
    HADOOP_VERSION="${HADOOP_VERSION}-instr"
  fi

  #make sure all spawned background jobs and services are stoped or killed when done
  if [ "$INSTRUMENTATION" == "1" ] ; then
    update_traps "stop_hadoop; stop_monit; stop_sniffer;" "update_logger"
  else
    update_traps "stop_hadoop; stop_monit;" "update_logger"
  fi

#logger "INFO: DEBUG: userAloja=$userAloja
#DEBUG: BENCH_SHARE_DIR=$BENCH_SHARE_DIR
#BENCH_LOCAL_DIR=$BENCH_LOCAL_DIR
#BENCH_SOURCE_DIR=$BENCH_SOURCE_DIR
#BENCH_SAVE_PREPARE_LOCATION=$BENCH_SAVE_PREPARE_LOCATION
#HADOOP_VERSION=$HADOOP_VERSION
#DEBUG: JAVA_HOME=$JAVA_HOME
#JAVA_XMS=$JAVA_XMS JAVA_XMX=$JAVA_XMX
#PHYS_MEM=$PHYS_MEM
#NUM_CORES=$NUM_CORES
#CONTAINER_MIN_MB=$CONTAINER_MIN_MB
#CONTAINER_MAX_MB=$CONTAINER_MAX_MB
#MAPS_MB=$MAPS_MB
#AM_MB=$AM_MB
#JAVA_AM_XMS=$JAVA_AM_XMS
#JAVA_AM_XMX=$JAVA_AM_XMX
#REDUCES_MB=$REDUCES_MB
#Master node: $master_name "

}

get_hadoop_ports() {

# Hadop 1 ports
#  <name>dfs.datanode.address</name>
#  <value>##HOST##:##PORT_PREFIX##0010</value>
#  <name>dfs.datanode.ipc.address</name>
#  <value>##HOST##:##PORT_PREFIX##0020</value>
#  <name>dfs.http.address</name>
#  <value>##NAMENODE##:##PORT_PREFIX##0070</value>
#  <name>dfs.datanode.http.address</name>
#  <value>##HOST##:##PORT_PREFIX##0075</value>
#  <name>dfs.secondary.http.address</name>
#  <value>##NAMENODE##:##PORT_PREFIX##0090</value>
#  <name>dfs.backup.http.address</name>
#  <value>##NAMENODE##:##PORT_PREFIX##0105</value>
#
#  <name>mapred.job.tracker</name>
#  <value>##MASTER##:##PORT_PREFIX##8021</value>
#  <name>mapred.job.tracker.http.address</name>
#  <value>##MASTER##:##PORT_PREFIX##0030</value>
#  <name>mapred.task.tracker.http.address</name>
#  <value>##HOST##:##PORT_PREFIX##0060</value>
#  <!-- For infiniBand -->
#  <name>mapred.tasktracker.dns.interface</name>
#  <value>##IFACE##</value>
#  
#  <name>fs.default.name</name>
#  <value>hdfs://##NAMENODE##:##PORT_PREFIX##8020</value>

# For v2
  if [ "$(get_hadoop_major_version)" == "2" ]; then
    ports+="${PORT_PREFIX}0010
${PORT_PREFIX}0020
${PORT_PREFIX}0070
${PORT_PREFIX}0075
${PORT_PREFIX}0090
${PORT_PREFIX}0105
${PORT_PREFIX}8021
${PORT_PREFIX}0030
${PORT_PREFIX}0060
${PORT_PREFIX}8020
${PORT_PREFIX}8030
${PORT_PREFIX}8031
${PORT_PREFIX}8032"

# Master
#tcp        0      0 192.168.99.100:39888    0.0.0.0:*               LISTEN      1000       170001      25763/java
#tcp        0      0 0.0.0.0:10033           0.0.0.0:*               LISTEN      1000       169994      25763/java
#tcp6       0      0 192.168.99.100:8088     :::*                    LISTEN      1000       168076      25617/java
#tcp6       0      0 192.168.99.100:8033     :::*                    LISTEN      1000       168264      25617/java
# Data
#tcp        0      0 127.0.0.1:42433         0.0.0.0:*               LISTEN      1000       95790       29702/java

# For v1
  else
    ports="${PORT_PREFIX}0010
${PORT_PREFIX}0020
${PORT_PREFIX}0070
${PORT_PREFIX}0075
${PORT_PREFIX}0090
${PORT_PREFIX}0105
${PORT_PREFIX}8021
${PORT_PREFIX}0030
${PORT_PREFIX}0060
${PORT_PREFIX}8020"

  fi

  echo -e "$ports"
}

get_hive_env(){
  echo "export HADOOP_PREFIX=${BENCH_HADOOP_DIR} && \
        export HADOOP_USER_CLASSPATH_FIRST=true && \
        export PATH=$PATH:$HIVE_HOME/bin:$HADOOP_HOME/bin:$JAVA_HOME/bin && \
  "
}

prepare_hive_config() {

subs=$(cat <<EOF
s,##HADOOP_HOME##,$BENCH_HADOOP_DIR,g;
s,##HIVE_HOME##,$HIVE_HOME,g;
EOF
)

  #to avoid perl warnings
  export LC_CTYPE=en_US.UTF-8
  export LC_ALL=en_US.UTF-8

  logger "INFO: Copying Hive and Hive-testbench dirs"
  $DSH "cp -ru $BENCH_SOURCE_DIR/apache-hive-1.2.0-bin $HIVE_B_DIR/"

  $DSH "/usr/bin/perl -pe \"$subs\" $HIVE_HOME/conf/hive-env.sh.template > $HIVE_HOME/conf/hive-env.sh"
  $DSH "/usr/bin/perl -pe \"$subs\" $HIVE_HOME/conf/hive-default.xml.template > $HIVE_HOME/conf/hive-default.xml"
  $DSH "/usr/bin/perl -pe \"$subs\" $HIVE_HOME/conf/hive-log4j.properties.template > $HIVE_HOME/conf/hive-log4j.properties"
  $DSH "/usr/bin/perl -pe \"$subs\" $TPCH_SOURCE_DIR/sample-queries-tpch/$TPCH_SETTINGS_FILE_NAME.template > $TPCH_SOURCE_DIR/sample-queries-tpch/$TPCH_SETTINGS_FILE_NAME"
}

# Sets the substitution values for the hadoop config
get_substitutions() {

  #generate the path for the hadoop config files, including support for multiple volumes
  HDFS_NDIR="$(get_hadoop_conf_dir "$DISK" "dfs/name" "$PORT_PREFIX")"
  HDFS_DDIR="$(get_hadoop_conf_dir "$DISK" "dfs/data" "$PORT_PREFIX")"

  IO_MB="$((IO_FACTOR * 10))"
  MAX_REDS="$MAX_MAPS"

  cat <<EOF
s,##JAVA_HOME##,$(get_java_home),g;
s,##HADOOP_HOME##,$BENCH_HADOOP_DIR,g;
s,##JAVA_XMS##,$JAVA_XMS,g;
s,##JAVA_XMX##,$JAVA_XMX,g;
s,##JAVA_AM_XMS##,$JAVA_AM_XMS,g;
s,##JAVA_AM_XMX##,$JAVA_AM_XMX,g;
s,##LOG_DIR##,$HDD/logs,g;
s,##REPLICATION##,$REPLICATION,g;
s,##MASTER##,$master_name,g;
s,##NAMENODE##,$master_name,g;
s,##TMP_DIR##,$HDD_TMP,g;
s,##HDFS_NDIR##,$HDFS_NDIR,g;
s,##HDFS_DDIR##,$HDFS_DDIR,g;
s,##MAX_MAPS##,$MAX_MAPS,g;
s,##MAX_REDS##,$MAX_REDS,g;
s,##IFACE##,$IFACE,g;
s,##IO_FACTOR##,$IO_FACTOR,g;
s,##IO_MB##,$IO_MB,g;
s,##PORT_PREFIX##,$PORT_PREFIX,g;
s,##IO_FILE##,$IO_FILE,g;
s,##BLOCK_SIZE##,$BLOCK_SIZE,g;
s,##PHYS_MEM##,$PHYS_MEM,g;
s,##NUM_CORES##,$NUM_CORES,g;
s,##CONTAINER_MIN_MB##,$CONTAINER_MIN_MB,g;
s,##CONTAINER_MAX_MB##,$CONTAINER_MAX_MB,g;
s,##MAPS_MB##,$MAPS_MB,g;
s,##REDUCES_MB##,$REDUCES_MB,g;
s,##AM_MB##,$REDUCES_MB,g;
s,##BENCH_LOCAL_DIR##,$BENCH_LOCAL_DIR,g;
s,##HDD##,$HDD,g;
EOF
}

prepare_hadoop_config(){

  logger "INFO: Preparing Hadoop run specific config"
  $DSH "mkdir -p '$HDD/conf'; cp -r $(get_local_configs_path)/$(get_hadoop_config_folder)/* '$HDD/conf';"

  # To avoid perl warnings
  local export_perl="
export LC_CTYPE=en_US.UTF-8;
export LC_ALL=en_US.UTF-8;
"

  # Get the values
  subs=$(get_substitutions)
  slaves="$(get_hadoop_slaves "$node_names" "$master_name")"

  $DSH "
$export_perl
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/hadoop-env.sh;
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/core-site.xml;
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/hdfs-site.xml;
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/mapred-site.xml
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/hadoop-metrics.properties
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/hadoop-metrics2.properties

echo -e '$master_name' > $HADOOP_CONF_DIR/masters;
echo -e \"$slaves\" > $HADOOP_CONF_DIR/slaves;"


  # Extra config for v2
  if [ "$(get_hadoop_major_version)" == "2" ]; then
    $DSH "
$export_perl
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/yarn-site.xml;
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/yarn-env.sh;
/usr/bin/perl -i -pe \"$subs\" $HADOOP_CONF_DIR/mapred-env.sh"
  fi

  # TODO this part need to be improved, it needs the node for multiple hostnames in a machine (eg. when IB)
  logger "INFO: Replacing per host config"
  for node in $node_names ; do
    ssh "$node" "
$export_perl
/usr/bin/perl -i -pe \"s,##HOST##,$node,g;\" $HADOOP_CONF_DIR/mapred-site.xml
/usr/bin/perl -i -pe \"s,##HOST##,$node,g;\" $HADOOP_CONF_DIR/hdfs-site.xml"
    # Extra config for v2
    if [ "$(get_hadoop_major_version)" == "2" ]; then
      ssh "$node" "$export_perl
/usr/bin/perl -i -pe \"s,##HOST##,$node,g;\" $HADOOP_CONF_DIR/yarn-site.xml"
    fi
  done

  # Save config
  logger "INFO: Saving bench spefic config to job folder"
  for node in $node_names ; do
    ssh "$node" "
mkdir -p $JOB_PATH/conf_$node;
cp $HADOOP_CONF_DIR/* $JOB_PATH/conf_$node/" &
  done

  if [ "$DELETE_HDFS" == "1" ] ; then
    format_HDFS "$(get_hadoop_major_version)"
  else
    logger "INFO: Deleting previous Job history files (in case necessary)"
    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hdfs dfs -rm -r /tmp/hadoop-yarn/history" 2> /dev/null
  fi

  # Set correct permissions for instrumentation's sniffer
  [ "$INSTRUMENTATION" == "1" ] && instrumentation_set_perms
}

# Returns if Hadoop v1 or v2
# $1 the hadoop string (optional, if not uses $HADOOP_VERSION)
get_hadoop_major_version() {
  if [ "$1" ] ; then
    local hadoop_string="$1"
  else
    local hadoop_string="$HADOOP_VERSION"
  fi

  local major_version
  if [ "$clusterType" == "PaaS" ]; then
    major_version="2"
  elif [[ "$hadoop_string" == *"p-1"* ]] ; then
    major_version="1"
  elif [[ "$hadoop_string" == *"p-2"* ]] ; then
    major_version="2"
  else
    die "Cannot determine Hadoop major version.  Supplied version $hadoop_string"
  fi

  echo -e "$major_version"
}

# Formats the HDFS and NameNode for both Hadoop versions
# $1 $HADOOP_VERSION
format_HDFS(){
  local hadoop_version="$1"
  logger "INFO: Formating HDFS and NameNode dirs"

  if [ "$(get_hadoop_major_version)" == "1" ]; then
    $DSH_MASTER "$HADOOP_EXPORTS yes Y | $BENCH_HADOOP_DIR/bin/hadoop namenode -format"
    $DSH_MASTER "$HADOOP_EXPORTS yes Y | $BENCH_HADOOP_DIR/bin/hadoop datanode -format"
  elif [ "$(get_hadoop_major_version)" == "2" ] ; then
    $DSH_MASTER "$HADOOP_EXPORTS yes Y | $BENCH_HADOOP_DIR/bin/hdfs namenode -format"
    $DSH_MASTER "$HADOOP_EXPORTS yes Y | $BENCH_HADOOP_DIR/bin/hdfs datanode -format"
  else
    die "Incorrect Hadoop version. Supplied: $(get_hadoop_major_version)"
  fi
}

# Just an alias
start_hadoop() {
  restart_hadoop
}

restart_hadoop(){
  logger "INFO: Restart Hadoop"
  #just in case stop all first
  stop_hadoop

  if [ "$(get_hadoop_major_version)" == "1" ]; then
    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/start-all.sh"
  elif [ "$(get_hadoop_major_version)" == "2" ] ; then
    $DSH_MASTER "$HADOOP_EXPORTS
$BENCH_HADOOP_DIR/sbin/start-dfs.sh &
$BENCH_HADOOP_DIR/sbin/start-yarn.sh &
$BENCH_HADOOP_DIR/sbin/mr-jobhistory-daemon.sh start historyserver &
wait"
  else
    die "Incorrect Hadoop version. Supplied: $(get_hadoop_major_version)"
  fi

  for i in {0..300} #3mins
  do
    if [ "$(get_hadoop_major_version)" == "1" ]; then
      local report=$($DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hadoop dfsadmin -report 2> /dev/null")
      local num=$(echo "$report" | grep "Datanodes available" | awk '{print $3}')
      local safe_mode=$(echo "$report" | grep "Safe mode is ON")
    elif [ "$(get_hadoop_major_version)" == "2" ] ; then
      local report=$($DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hdfs dfsadmin -report 2> /dev/null")
      local num=$(echo "$report" | grep "Live datanodes" | awk '{print $3}')
      num="${num:1:${#num}-3}"
      local safe_mode=$(echo "$report" | grep "Safe mode is ON")
    else
      die "Incorrect Hadoop version. Supplied: $(get_hadoop_major_version)"
    fi

    logger "$report"

    if [ "$num" == "$NUMBER_OF_DATA_NODES" ] ; then
      if [[ -z $safe_mode ]] ; then
        #everything fine continue
        break
      elif [ "$i" == "30" ] ; then
        logger "INFO: Still in Safe mode, MANUALLY RESETTING SAFE MODE wating for $i seconds"
        if [ "$(get_hadoop_major_version)" == "1" ]; then
          $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hadoop dfsadmin -safemode leave"
        elif [ "$(get_hadoop_major_version)" == "2" ] ; then
          $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hdfs dfsadmin -safemode leave 2>&1"
        else
          die "Incorrect Hadoop version. Supplied: $(get_hadoop_major_version)"
        fi
      else
        logger "INFO: Still in Safe mode, wating for $i seconds"
      fi
    elif [ "$i" == "60" ] && [[ -z $1 ]] ; then
      #try to restart hadoop deleting files and prepare again files
      if [ "$(get_hadoop_major_version)" == "1" ]; then
        $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/sbin/stop-dfs.sh" 2>&1 >> $LOG_PATH
        $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/sbin/stop-yarn.sh" 2>&1 >> $LOG_PATH
        $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/sbin/mr-jobhistory-daemon.sh stop historyserver"
        $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/sbin/start-dfs.sh"
        $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/sbin/start-yarn.sh"
        $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/sbin/mr-jobhistory-daemon.sh start historyserver"
      elif [ "$(get_hadoop_major_version)" == "2" ] ; then
        $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hdfs dfsadmin -safemode leave 2>&1"
      else
        die "Incorrect Hadoop version. Supplied: $(get_hadoop_major_version)"
      fi
    elif [ "$i" == "180" ] && [[ -z $1 ]] ; then
      #try to restart hadoop deleting files and prepare again files
      logger "INFO: Reseting config to retry DELETE_HDFS WAS SET TO: $DELETE_HDFS"
      DELETE_HDFS="1"
      restart_hadoop no_retry
    elif [ "$i" == "120" ] ; then
      die "$num/$NUMBER_OF_DATA_NODES Datanodes available, EXIT"
    else
      logger "INFO: $num/$NUMBER_OF_DATA_NODES Datanodes available, wating for $i seconds"
      sleep 0.5
    fi
  done

  set_omm_killer

  logger "INFO: Hadoop ready"
}

# Stops Hadoop and checks for open ports
# $1 retry (to prevent recursion)
stop_hadoop(){
  local dont_retry="$1"

 if [ "$clusterType=" != "PaaS" ]; then
  if [ ! "$dont_retry" ] ; then
    logger "INFO: Stop Hadoop"
  else
    logger "INFO: Stop Hadoop (retry)"
  fi

  if [ "$(get_hadoop_major_version)" == "1" ]; then
    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/stop-all.sh"
  elif [ "$(get_hadoop_major_version)" == "2" ] ; then
    $DSH_MASTER "
$HADOOP_EXPORTS $BENCH_HADOOP_DIR/sbin/stop-yarn.sh &
$BENCH_HADOOP_DIR/sbin/stop-dfs.sh &
$BENCH_HADOOP_DIR/sbin/mr-jobhistory-daemon.sh stop historyserver &
wait"
  else
    die "Incorrect Hadoop version. Supplied: $(get_hadoop_major_version)"
  fi

  logger "INFO: testing Hadoop port for running processes"
  local hadoop_ports="$(get_hadoop_ports)"
  local open_port=""

  # First tell all ports toguether to save time
  local test_all_cmd
  local all_ports
  for port in $hadoop_ports ; do
    test_all_cmd+="lsof -i tcp:$port || "
    all_ports+="$port "
  done
  logger "DEBUG: Testing for open ports in: $all_ports"
  sleep 0.5 # give some chance of stopping by themselves
  if ! test_nodes_inverse "${test_all_cmd:0:(-3)}" "WARNING" ; then
    open_port="true"
  else
    logger "DEBUG: All ports empty"
  fi

  # If any found, go one by one
  if [ "$open_port" ] ; then
    for port in $hadoop_ports ; do
      logger "DEBUG: testing port:$port"
      if ! test_nodes_inverse "lsof -i tcp:$port" "WARNING" ; then
        open_port="true"
        logger "ERROR: port:$port not empty, attempting to kill it gracefully"
        kill_on_port "$port"
      else
        logger "DEBUG: port:$port empty"
      fi
    done
  fi

  if [ "$open_port" ] && [ "$dont_retry" ] ; then
    logger "ERROR: Please manually stop running Hadoop instances"
    #die "Please manually stop running Hadoop instances"
  elif [ "$open_port" ] && [ ! "$retry" ] ; then
    stop_hadoop "dont_retry"
  else
    logger "INFO: Stop Hadoop ready"
  fi
 fi
}

# Performs the actual benchmark execution
# TODO old code needs cleanup
# $1 benchmark name
# $2 command
# $3 if prepare (optional)
execute_hadoop(){
  local bench="$1"
  local cmd="$2"
  local prefix="$3"

  save_disk_usage "BEFORE"

  restart_monit

  #TODO fix empty variable problem when not echoing
  local start_exec="$(timestamp)"
  local start_date="$(date --date='+1 hour' '+%Y%m%d%H%M%S')"
  logger "INFO: RUNNING ${prefix}${bench}"

  #TODO refactor
  local hadoop_exports
  if [ "$EXECUTE_HIBENCH" ] ; then
    hadoop_exports="$(get_HiBench_exports)
$(get_hadoop_exports)"
  else
    hadoop_exports="$(get_hadoop_exports)"
  fi

  logger "DEBUG: $hadoop_exports"

  $DSH_MASTER "$hadoop_exports /usr/bin/time -f 'Time ${prefix}${bench} %e' $cmd"

  local end_exec="$(timestamp)"

  local total_secs=`calc_exec_time $start_exec $end_exec`
  logger "DONE RUNNING $bench Total time: ${total_secs}secs."

  # Save execution information in an array to allow import later
  
  EXEC_TIME["${prefix}${bench}"]="$total_secs"
  EXEC_START["${prefix}${bench}"]="$start_exec"
  EXEC_END["${prefix}${bench}"]="$end_exec"

  #url="http://minerva.bsc.es:8099/zabbix/screens.php?&fullscreen=0&elementid=AZ&stime=${start_date}&period=${total_secs}"
  #echo "SENDING: hibench.runs $end_exec <a href='$url'>${prefix}${bench} $CONF</a> <strong>Time:</strong> $total_secs s."
  #zabbix_sender "hibench.runs $end_exec <a href='$url'>${prefix}${bench} $CONF</a> <strong>Time:</strong> $total_secs s."

  stop_monit

  #save the prepare
  if [[ -z "$prefix" ]] && [ "$SAVE_BENCH" == "1" ] ; then
    logger "INFO: Saving $prefix to disk: $BENCH_SAVE_PREPARE_LOCATION"
    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hadoop fs -get -ignoreCrc /HiBench $BENCH_SAVE_PREPARE_LOCATION"
  fi

  save_disk_usage "AFTER"

  #clean output data
  logger "INFO: Cleaning output data for $bench"
  if [[ "$bench" == "dfsioe"* ]] ; then
    local folder_in_HDFS="/benchmarks/TestDFSIO-Enh/Output"
  else
    local folder_in_HDFS="/HiBench/$(get_bench_name "$bench")/Output"
  fi

  if [ "$(get_hadoop_major_version)" == "1" ]; then
    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hadoop fs -rmr $folder_in_HDFS"
  elif [ "$(get_hadoop_major_version)" == "2" ] ; then
    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hdfs dfs -rm -r $folder_in_HDFS"
  else
    die "Incorrect Hadoop version. Supplied: $(get_hadoop_major_version)"
  fi

  save_hadoop "${3}${1}"
}

# Returns the the path to the hadoop binary with the proper exports
get_hadoop_cmd() {
  local hadoop_exports
  local hadoop_cmd

  #TODO refactor
  if [ "$EXECUTE_HIBENCH" ] ; then
    hadoop_exports="$(get_HiBench_exports)
$(get_hadoop_exports)"
  else
    hadoop_exports="$(get_hadoop_exports)"
  fi

  hadoop_cmd="$hadoop_exports $BENCH_HADOOP_DIR/bin/hadoop"

  echo -e "$hadoop_cmd"
}

# Performs the actual benchmark execution
# $1 benchmark name
# $2 command
# $3 if to time exec
execute_hadoop_new(){
  local bench="$1"
  local cmd="$2"
  local time_exec="$3"

  local hadoop_cmd="$(get_hadoop_cmd) $cmd"

  logger "DEBUG: Hadoop command:$hadoop_cmd"

  if [ "$time_exec" ] ; then
    save_disk_usage "BEFORE"
    restart_monit
    set_bench_start "$bench"
  fi

  # Run the command and time it
  time_cmd_master "$hadoop_cmd" "$time_exec"

  if [ "$time_exec" ] ; then
    set_bench_end "$bench"
    stop_monit
    save_disk_usage "AFTER"
    save_hadoop "$bench"
  fi
}

# Deletes a file or directory recursively in HDFS
# $1 bench name
# $2 delete cmd
hadoop_delete_path() {
  local bench_name="$1"
  local path_to_delete="$2"

  if [ "$(get_hadoop_major_version)" == "2" ]; then
    local delete_cmd="-rm -r -f -skipTrash"
  else
    local delete_cmd="-rmr -skipTrash"
  fi

  execute_hadoop_new "$bench_name: deleting $path_to_delete" "fs $delete_cmd $path_to_delete"
}


execute_hdi_hadoop() {
  save_disk_usage "BEFORE"

  restart_monit

  #TODO fix empty variable problem when not echoing
  local start_exec=`timestamp`
  local start_date=$(date --date='+1 hour' '+%Y%m%d%H%M%S')
  logger "INFO: # EXECUTING ${3}${1}"
  local HADOOP_EXECUTABLE=hadoop
  local HADOOP_EXAMPLES_JAR=/home/pristine/hadoop-mapreduce-examples.jar
  if [ "$defaultProvider" == "rackspacecbd" ]; then
    HADOOP_EXECUTABLE='sudo -u hdfs hadoop'
    HADOOP_EXAMPLES_JAR=/usr/hdp/current/hadoop-mapreduce-client/hadoop-mapreduce-examples.jar
  fi

  #need to send all the environment variables over SSH
  EXP="export JAVA_HOME=$JAVA_HOME && \
export HADOOP_HOME=/usr/hdp/2.*/hadoop && \
export HADOOP_EXECUTABLE='$HADOOP_EXECUTABLE' && \
export HADOOP_CONF_DIR=/etc/hadoop/conf && \
export HADOOP_EXAMPLES_JAR='$HADOOP_EXAMPLES_JAR' && \
export MAPRED_EXECUTABLE=ONLY_IN_HADOOP_2 && \
export HADOOP_VERSION=$HADOOP_VERSION && \
export COMPRESS_GLOBAL=$COMPRESS_GLOBAL && \
export COMPRESS_CODEC_GLOBAL=$COMPRESS_CODEC_GLOBAL && \
export COMPRESS_CODEC_MAP=$COMPRESS_CODEC_MAP && \
export NUM_MAPS=$NUM_MAPS && \
export NUM_REDS=$NUM_REDS && \
export DATASIZE=$DATASIZE && \
export PAGES=$PAGES && \
export CLASSES=$CLASSES && \
export NGRAMS=$NGRAMS && \
export RD_NUM_OF_FILES=$RD_NUM_OF_FILES && \
export RD_FILE_SIZE=$RD_FILE_SIZE && \
export WT_NUM_OF_FILES=$WT_NUM_OF_FILES && \
export WT_FILE_SIZE=$WT_FILE_SIZE && \
export NUM_OF_CLUSTERS=$NUM_OF_CLUSTERS && \
export NUM_OF_SAMPLES=$NUM_OF_SAMPLES && \
export SAMPLES_PER_INPUTFILE=$SAMPLES_PER_INPUTFILE && \
export DIMENSIONS=$DIMENSIONS && \
export MAX_ITERATION=$MAX_ITERATION && \
export NUM_ITERATIONS=$NUM_ITERATIONS && \
"

  $DSH_MASTER "$EXP /usr/bin/time -f 'Time ${3}${1} %e' $2"

  local end_exec=`timestamp`

  logger "INFO: # DONE EXECUTING $1"

  local total_secs=`calc_exec_time $start_exec $end_exec`
  echo "end total sec $total_secs"

  # Save execution information in an array to allow import later
  
  EXEC_TIME[${3}${1}]="$total_secs"
  EXEC_START[${3}${1}]="$start_exec"
  EXEC_END[${3}${1}]="$end_exec"

  url="http://minerva.bsc.es:8099/zabbix/screens.php?&fullscreen=0&elementid=AZ&stime=${start_date}&period=${total_secs}"
  echo "SENDING: hibench.runs $end_exec <a href='$url'>${3}${1} $CONF</a> <strong>Time:</strong> $total_secs s."
  zabbix_sender "hibench.runs $end_exec <a href='$url'>${3}${1} $CONF</a> <strong>Time:</strong> $total_secs s."


  stop_monit

  #save the prepare
  if [[ -z $3 ]] && [ "$SAVE_BENCH" == "1" ] ; then
    logger "INFO: Saving $3 to disk: $BENCH_SAVE_PREPARE_LOCATION"
    $DSH_MASTER hadoop fs -get -ignoreCrc /HiBench $BENCH_SAVE_PREPARE_LOCATION
  fi

  save_disk_usage "AFTER"

  #TODO should move to cleanup function
  #clean output data
  logger "INFO: Cleaning output data for $bench"
  $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hadoop fs -rmr /HiBench/$(get_bench_name "$1")/Output"

  save_hadoop "${3}${1}"
}

save_hadoop() {
  logger "INFO: Saving benchmark $1"
  $DSH "mkdir -p $JOB_PATH/$1"
  $DSH "mv $HDD/{bwm,vmstat}*.log $HDD/sar*.sar $JOB_PATH/$1/ 2> /dev/null"

  # Save hadoop logs
  # Hadoop 2 saves job history to HDFS, get it from there
  if [ "$clusterType" == "PaaS" ]; then
    if [ "$defaultProvider" == "rackspacecbd" ]; then
        sudo su hdfs -c "hdfs dfs -chmod -R 777 /mr-history"
        hdfs dfs -copyToLocal "/mr-history" "$JOB_PATH/$1"
        sudo su hdfs -c "hdfs dfs -rm -r /mr-history/*"
        sudo su hdfs -c "hdfs dfs -expunge"
    else
	    hdfs dfs -copyToLocal "/mr-history" "$JOB_PATH/$1"
	    hdfs dfs -rm -r "/mr-history"
	    hdfs dfs -expunge
    fi
  else
    #we cannot move hadoop files
    #take into account naming *.date when changing dates
    #$DSH "cp $HDD/logs/hadoop-*.{log,out}* $JOB_PATH/$1/"
    #$DSH "cp -r ${BENCH_HADOOP_DIR}/logs/* $JOB_PATH/$1/ 2> /dev/null"
    $DSH "cp -r $HDD/logs/* $JOB_PATH/$1/ 2> /dev/null"
  fi

  # Hadoop 2 saves job history to HDFS, get it from there and then delete
  if [[ "$(get_hadoop_major_version)" == "2" && "$clusterType=" != "PaaS" ]]; then
    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hdfs dfs -copyToLocal /tmp/hadoop-yarn/staging/history $JOB_PATH/$1"
    logger "INFO: Deleting history files after copy to local"
#    $DSH_MASTER "$HADOOP_EXPORTS $BENCH_HADOOP_DIR/bin/hdfs dfs -rm -r /tmp/hadoop-yarn/staging/history"
  fi

  if [[ "EXECUTE_HIBENCH" == "true" ]]; then
    #$DSH "cp $HADOOP_DIR/conf/* $JOB_PATH/$1"
    $DSH_MASTER  "mv $BENCH_HIB_DIR/$bench/hibench.report  $JOB_PATH/$1/"
  fi

  #logger "INFO: Copying files to master == scp -r $JOB_PATH $MASTER:$JOB_PATH"
  #$DSH "scp -r $JOB_PATH $MASTER:$JOB_PATH"
  #pending, delete

  # Save sysstat data for instrumentation
  if [ "$INSTRUMENTATION" == "1" ] ; then
    $DSH "mkdir -p $JOB_PATH/traces"
    $DSH "cp $JOB_PATH/$1/sar*.sar $JOB_PATH/traces/"
  fi

  logger "INFO: Compresing and deleting $1"

  $DSH_MASTER "
cd $JOB_PATH;
tar -cjf $JOB_PATH/$1.tar.bz2 $1;
rm -rf $JOB_PATH/$1;
if [ \"\$(ls conf_* 2> /dev/null)\" ] ; then
  tar -cjf $JOB_PATH/host_conf.tar.bz2 conf_*;
  rm -rf
fi
"

  logger "INFO: Done saving benchmark $1"
}