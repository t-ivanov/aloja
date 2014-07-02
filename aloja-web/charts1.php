<?php

ini_set('memory_limit', '512M');

require_once 'inc/common.php';
require_once 'inc/HighCharts.php';

try {
    //TODO fix, initialize variables
    get_exec_details('1', 'id_exec');

    //check the URL
    $execs = get_GET_execs();

    if (get_GET_string('random') && !$execs) {
        $keys = array_keys($exec_rows);
        $execs = array_unique(array($keys[array_rand($keys)], $keys[array_rand($keys)]));
    }
    if (get_GET_string('hosts')) {
        $hosts = get_GET_string('hosts');
    } else {
        $hosts = 'Slaves';
    }
    if (get_GET_string('metric')) {
        $metric = get_GET_string('metric');
    } else {
        $metric = 'CPU';
    }

    if (get_GET_string('aggr')) {
        $aggr = get_GET_string('aggr');
    } else {
        $aggr = 'AVG';
    }

    if ($aggr == 'AVG') {
        $aggr_text = "Average";
    } elseif ($aggr == 'SUM') {
        $aggr_text = "SUM";
    } else {
        throw new Exception("Aggregation type '$aggr' is not valid.");
    }


    if ($hosts == 'Slaves') {
        $selected_hosts = array('minerva-1002', 'minerva-1003', 'minerva-1004', 'al-1002', 'al-1003', 'al-1004');
    } elseif ($hosts == 'Master') {
        $selected_hosts = array('minerva-1001', 'al-1001');
    } else {
        $selected_hosts = array($hosts);
    }

    $charts = array();
    $exec_details = array();
    $chart_details = array();

    $clusters = array();

    foreach ($execs as $exec) {
        //do a security check
        $tmp = filter_var($exec, FILTER_SANITIZE_NUMBER_INT);
        if (!is_numeric($tmp) || !($tmp > 0) ) {
            unset($execs[$exec]);
            continue;
        }

        $exec_title = get_exec_details($exec, 'exec');

        $pos_name = strpos($exec_title, '/');
        $exec_title =
            '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'.
            strtoupper(substr($exec_title, ($pos_name+1))).
            '&nbsp;'.
            ((strpos($exec_title, '_az') > 0) ? 'AZURE':'LOCAL').
            "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ID_$exec ".
            substr($exec_title, 21, (strlen($exec_title) - $pos_name - ((strpos($exec_title, '_az') > 0) ? 21:18)))
        ;

        $exec_details[$exec]['time']        = get_exec_details($exec, 'exe_time');
        $exec_details[$exec]['start_time']  = get_exec_details($exec, 'start_time');
        $exec_details[$exec]['end_time']    = get_exec_details($exec, 'end_time');

        $id_cluster = get_exec_details($exec, 'id_cluster');
        if (!in_array($id_cluster, $clusters)) $clusters[] = $id_cluster;

        //$end_time = get_exec_details($exec, 'init_time');

        $date_where     = " AND date BETWEEN '{$exec_details[$exec]['start_time']}' and '{$exec_details[$exec]['end_time']}' ";

        $where          = " WHERE id_exec = '$exec' AND host IN ('".join("','", $selected_hosts)."') $date_where";
        $where_BWM      = " WHERE id_exec = '$exec' AND host IN ('".join("','", $selected_hosts)."') ";

        $where_VMSTATS  = " WHERE id_exec = '$exec' AND host IN ('".join("','", $selected_hosts)."') ";

        $group_by   = ' GROUP BY date ORDER by date'; //UNIX_TIMESTAMP(date) DIV 1

        $group_by_vmstats = ' GROUP BY time ORDER by time';
        $group_by_BWM = ' GROUP BY unix_timestamp ORDER by unix_timestamp';

        $charts[$exec] = array(
            'job_status' => array(
                'metric'    => "ALL",
                'query'     => "SELECT time_to_sec(timediff(date, '{$exec_details[$exec]['start_time']}')) time,
                                maps map,shuffle,merge,reduce,waste FROM JOB_status
                                WHERE id_exec = '$exec' $date_where GROUP BY job_name, date ORDER by job_name, date;",
                'fields'    => array('map', 'shuffle', 'reduce', 'waste', 'merge'),
                'title'     => "Job exectution history $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'cpu' => array(
                'metric'    => "CPU",
                'query'     => "SELECT $aggr(`%user`) `%user`, $aggr(`%system`) `%system`, $aggr(`%steal`) `%steal`, $aggr(`%iowait`)
                            `%iowait`, $aggr(`%nice`) `%nice` FROM SAR_cpu $where $group_by;",
                'fields'    => array('%user', '%system', '%steal', '%iowait', '%nice'),
                'title'     => "CPU Utilization ($aggr_text, $hosts) $exec_title ",
                'percentage'=> ($aggr == 'SUM' ? '300':100),
                'stacked'   => true,
                'negative'  => false,
            ),
            'load' => array(
                'metric'    => "CPU",
                'query' => "SELECT $aggr(`ldavg-1`) `ldavg-1`, $aggr(`ldavg-5`) `ldavg-5`, $aggr(`ldavg-15`) `ldavg-15`
                        FROM SAR_load $where $group_by;",
                'fields'    => array('ldavg-15', 'ldavg-5', 'ldavg-1'),
                'title'     => "CPU Load Averge ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'load_queues' => array(
                'metric'    => "CPU",
                'query' => "SELECT $aggr(`runq-sz`) `runq-sz`, $aggr(`blocked`) `blocked`
                        FROM SAR_load $where $group_by;",
                'fields'    => array('runq-sz', 'blocked'),
                'title'     => "CPU Queues ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'load_tasks' => array(
                'metric'    => "CPU",
                'query' => "SELECT $aggr(`plist-sz`) `plist-sz` FROM SAR_load $where $group_by;",
                'fields'    => array('plist-sz'),
                'title'     => "Number of tasks for CPUs ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'switches' => array(
                'metric'    => "CPU",
                'query'     => "SELECT $aggr(`proc/s`) `proc/s`, $aggr(`cswch/s`) `cswch/s` FROM SAR_switches $where $group_by;",
                'fields'    => array('proc/s', 'cswch/s'),
                'title'     => "CPU Context Switches ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'interrupts' => array(
                'metric'    => "CPU",
                'query' => "SELECT $aggr(`intr/s`) `intr/s` FROM SAR_interrupts $where $group_by;",
                'fields'    => array('intr/s'),
                'title'     => "CPU Interrupts ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'memory_util' => array(
                'metric'    => "Memory",
                'query' => "SELECT  $aggr(kbmemfree)*1024 kbmemfree, $aggr(kbmemused)*1024 kbmemused
                                FROM SAR_memory_util $where $group_by;",
                'fields'    => array('kbmemfree', 'kbmemused'),
                'title'     => "Memory Utilization ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => true,
                'negative'  => false,
            ),
            'memory_util_det' => array(
                'metric'    => "Memory",
                'query' => "SELECT  $aggr(kbbuffers)*1024 kbbuffers,  $aggr(kbcommit)*1024 kbcommit, $aggr(kbcached)*1024 kbcached,
                                $aggr(kbactive)*1024 kbactive, $aggr(kbinact)*1024 kbinact
                                FROM SAR_memory_util $where $group_by;",
                'fields'    => array('kbcached', 'kbbuffers', 'kbinact', 'kbcommit',  'kbactive'), //
                'title'     => "Memory Utilization Details ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => true,
                'negative'  => false,
            ),
//            'memory_util3' => array(
//                'query' => "SELECT $aggr(`%memused`) `%memused`, $aggr(`%commit`) `%commit` FROM SAR_memory_util $where $group_by;",
//                'fields'    => array('%memused', '%commit',),
//                'title'     => "Memory Utilization % ($aggr_text, $hosts) $exec_title ",
//                'percentage'=> true,
//                'stacked'   => false,
//                'negative'  => false,
//            ),
            'memory' => array(
                'metric'    => "Memory",
                'query' => "SELECT $aggr(`frmpg/s`) `frmpg/s`, $aggr(`bufpg/s`) `bufpg/s`, $aggr(`campg/s`) `campg/s`
                            FROM SAR_memory $where $group_by;",
                'fields'    => array('frmpg/s','bufpg/s','campg/s'),
                'title'     => "Memory Stats ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false, //este tiene valores negativos...
            ),
            'io_pagging_disk' => array(
                'metric'    => "Memory",
                'query' => "SELECT $aggr(`pgpgin/s`)*1024 `pgpgin/s`, $aggr(`pgpgout/s`)*1024 `pgpgout/s`
                            FROM SAR_io_paging $where $group_by;",
                'fields'    => array('pgpgin/s', 'pgpgout/s'),
                'title'     => "I/O Paging IN/OUT to disk ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'io_pagging' => array(
                'metric'    => "Memory",
                'query' => "SELECT $aggr(`fault/s`) `fault/s`, $aggr(`majflt/s`) `majflt/s`, $aggr(`pgfree/s`) `pgfree/s`,
                            $aggr(`pgscank/s`) `pgscank/s`, $aggr(`pgscand/s`) `pgscand/s`, $aggr(`pgsteal/s`) `pgsteal/s`
                            FROM SAR_io_paging $where $group_by;",
                'fields'    => array('fault/s', 'majflt/s', 'pgfree/s', 'pgscank/s', 'pgscand/s', 'pgsteal/s'),
                'title'     => "I/O Paging ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'io_pagging_vmeff' => array(
                'metric'    => "Memory",
                'query' => "SELECT $aggr(`%vmeff`) `%vmeff` FROM SAR_io_paging $where $group_by;",
                'fields'    => array('%vmeff'),
                'title'     => "I/O Paging %vmeff ($aggr_text, $hosts) $exec_title ",
                'percentage'=> ($aggr == 'SUM' ? '300':100),
                'stacked'   => false,
                'negative'  => false,
            ),
            'io_transactions' => array(
                'metric'    => "Disk",
                'query' => "SELECT $aggr(`tps`) `tp/s`, $aggr(`rtps`) `read tp/s`, $aggr(`wtps`) `write tp/s`
                            FROM SAR_io_rate $where $group_by;",
                'fields'    => array('tp/s', 'read tp/s', 'write tp/s'),
                'title'     => "I/O Transactions/s ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'io_bytes' => array(
                'metric'    => "Disk",
                'query' => "SELECT $aggr(`bread/s`)/(1024) `KB_read/s`, $aggr(`bwrtn/s`)/(1024) `KB_wrtn/s`
                            FROM SAR_io_rate $where $group_by;",
                'fields'    => array('KB_read/s', 'KB_wrtn/s'),
                'title'     => "KB R/W ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
// All fields
//            'block_devices' => array(
//                'metric'    => "Disk",
//                'query' => "SELECT #$aggr(`tps`) `tps`, $aggr(`rd_sec/s`) `rd_sec/s`, $aggr(`wr_sec/s`) `wr_sec/s`,
//                                   $aggr(`avgrq-sz`) `avgrq-sz`, $aggr(`avgqu-sz`) `avgqu-sz`, $aggr(`await`) `await`,
//                                   $aggr(`svctm`) `svctm`, $aggr(`%util`) `%util`
//                            FROM (
//                                select
//                                id_exec, host, date,
//                                #sum(`tps`) `tps`,
//                                #sum(`rd_sec/s`) `rd_sec/s`,
//                                #sum(`wr_sec/s`) `wr_sec/s`,
//                                max(`avgrq-sz`) `avgrq-sz`,
//                                max(`avgqu-sz`) `avgqu-sz`,
//                                max(`await`) `await`,
//                                max(`svctm`) `svctm`,
//                                max(`%util`) `%util`
//                                from SAR_block_devices d WHERE id_exec = '$exec'
//                                GROUP BY date, host
//                            ) t $where $group_by;",
//                'fields'    => array('avgrq-sz', 'avgqu-sz', 'await', 'svctm', '%util'),
//                'title'     => "SAR Block Devices ($aggr_text, $hosts) $exec_title ",
//                'percentage'=> false,
//                'stacked'   => false,
//                'negative'  => false,
//            ),
            'block_devices_util' => array(
                'metric'    => "Disk",
                'query' => "SELECT $aggr(`%util_SUM`) `%util_SUM`, $aggr(`%util_MAX`) `%util_MAX`
                            FROM (
                                select
                                id_exec, host, date,
                                sum(`%util`) `%util_SUM`,
                                max(`%util`) `%util_MAX`
                                from SAR_block_devices d WHERE id_exec = '$exec'
                                GROUP BY date, host
                            ) t $where $group_by;",
                'fields'    => array('%util_SUM', '%util_MAX'),
                'title'     => "Disk Uitlization percentage (All DEVs, $aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'block_devices_await' => array(
                'metric'    => "Disk",
                'query' => "SELECT $aggr(`await_SUM`) `await_SUM`, $aggr(`await_MAX`) `await_MAX`
                            FROM (
                                select
                                id_exec, host, date,
                                sum(`await`) `await_SUM`,
                                max(`await`) `await_MAX`
                                from SAR_block_devices d WHERE id_exec = '$exec'
                                GROUP BY date, host
                            ) t $where $group_by;",
                'fields'    => array('await_SUM', 'await_MAX'),
                'title'     => "Disk request wait time in ms (All DEVs, $aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'block_devices_svctm' => array(
                'metric'    => "Disk",
                'query' => "SELECT $aggr(`svctm_SUM`) `svctm_SUM`, $aggr(`svctm_MAX`) `svctm_MAX`
                            FROM (
                                select
                                id_exec, host, date,
                                sum(`svctm`) `svctm_SUM`,
                                max(`svctm`) `svctm_MAX`
                                from SAR_block_devices d WHERE id_exec = '$exec'
                                GROUP BY date, host
                            ) t $where $group_by;",
                'fields'    => array('svctm_SUM', 'svctm_MAX'),
                'title'     => "Disk service time in ms (All DEVs, $aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'block_devices_queues' => array(
                'metric'    => "Disk",
                'query' => "SELECT $aggr(`avgrq-sz`) `avg-req-size`, $aggr(`avgqu-sz`) `avg-queue-size`
                            FROM (
                                select
                                id_exec, host, date,
                                max(`avgrq-sz`) `avgrq-sz`,
                                max(`avgqu-sz`) `avgqu-sz`
                                from SAR_block_devices d WHERE id_exec = '$exec'
                                GROUP BY date, host
                            ) t $where $group_by;",
                'fields'    => array('avg-req-size', 'avg-queue-size'),
                'title'     => "Disk req and queue sizes ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'vmstats_io' => array(
                'metric'    => "Disk",
                'query' => "SELECT $aggr(`bi`)/(1024) `KB_IN`, $aggr(`bo`)/(1024) `KB_OUT`
                            FROM VMSTATS $where_VMSTATS $group_by_vmstats;",
                'fields'    => array('KB_IN', 'KB_OUT'),
                'title'     => "VMSTATS KB I/O  ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'vmstats_rb' => array(
                'metric'    => "CPU",
                'query' => "SELECT $aggr(`r`) `runnable procs`, $aggr(`b`) `sleep procs` FROM VMSTATS $where_VMSTATS $group_by_vmstats;",
                'fields'    => array('runnable procs', 'sleep procs'),
                'title'     => "VMSTATS Processes (r-b)  ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'vmstats_memory' => array(
                'metric'    => "Memory",
                'query' => "SELECT  $aggr(`buff`) `buff`,
                                    $aggr(`cache`) `cache`,
                                    $aggr(`free`) `free`,
                                    $aggr(`swpd`) `swpd`
                                    FROM VMSTATS $where_VMSTATS $group_by_vmstats;",
                'fields'    => array('buff', 'cache', 'free', 'swpd'),
                'title'     => "VMSTATS Processes (r-b)  ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => true,
                'negative'  => false,
            ),
            'net_devices_kbs' => array(
                'metric'    => "Network",
                'query' => "SELECT $aggr(if(IFACE != 'lo', `rxkB/s`, NULL))/1024 `rxMB/s_NET`, $aggr(if(IFACE != 'lo', `txkB/s`, NULL))/1024 `txMB/s_NET`
                            FROM SAR_net_devices $where AND IFACE not IN ('') $group_by;",
                'fields'    => array('rxMB/s_NET', 'txMB/s_NET'),
                'title'     => "MB/s received and transmitted ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'net_devices_kbs_local' => array(
                'metric'    => "Network",
                'query' => "SELECT $aggr(if(IFACE =  'lo', `rxkB/s`, NULL))/1024 `rxMB/s_LOCAL`, $aggr(if(IFACE = 'lo', `txkB/s`, NULL))/1024 `txMB/s_LOCAL`
                            FROM SAR_net_devices $where AND IFACE not IN ('') $group_by;",
                'fields'    => array('rxMB/s_LOCAL', 'txMB/s_LOCAL'),
                'title'     => "MB/s received and transmitted LOCAL ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'net_devices_pcks' => array(
                'metric'    => "Network",
                'query' => "SELECT $aggr(if(IFACE != 'lo', `rxpck/s`, NULL))/1024 `rxpck/s_NET`, $aggr(if(IFACE != 'lo', `txkB/s`, NULL))/1024 `txpck/s_NET`
                            FROM SAR_net_devices $where AND IFACE not IN ('') $group_by;",
                'fields'    => array('rxpck/s_NET', 'txpck/s_NET'),
                'title'     => "Packets/s received and transmitted ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'net_devices_pcks_local' => array(
                'metric'    => "Network",
                'query' => "SELECT $aggr(if(IFACE =  'lo', `rxkB/s`, NULL))/1024 `rxpck/s_LOCAL`, $aggr(if(IFACE = 'lo', `txkB/s`, NULL))/1024 `txpck/s_LOCAL`
                            FROM SAR_net_devices $where AND IFACE not IN ('') $group_by;",
                'fields'    => array('rxpck/s_LOCAL', 'txpck/s_LOCAL'),
                'title'     => "Packets/s received and transmitted LOCAL ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'net_sockets_pcks' => array(
                'metric'    => "Network",
                'query' => "SELECT $aggr(`totsck`) `totsck`,
                                   $aggr(`tcpsck`) `tcpsck`,
                                   $aggr(`udpsck`) `udpsck`,
                                   $aggr(`rawsck`) `rawsck`,
                                   $aggr(`ip-frag`) `ipfrag`,
                                   $aggr(`tcp-tw`) `tcp-time-wait`
                            FROM SAR_net_sockets $where $group_by;",
                'fields'    => array('totsck', 'tcpsck', 'udpsck', 'rawsck', 'ip-frag', 'tcp-time-wait'),
                'title'     => "Packets/s received and transmitted ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'net_erros' => array(
                'metric'    => "Network",
                'query' => "SELECT $aggr(`rxerr/s`) `rxerr/s`,
                                   $aggr(`txerr/s`) `txerr/s`,
                                   $aggr(`coll/s`) `coll/s`,
                                   $aggr(`rxdrop/s`) `rxdrop/s`,
                                   $aggr(`txdrop/s`) `txdrop/s`,
                                   $aggr(`txcarr/s`) `txcarr/s`,
                                   $aggr(`rxfram/s`) `rxfram/s`,
                                   $aggr(`rxfifo/s`) `rxfifo/s`,
                                   $aggr(`txfifo/s`) `txfifo/s`
                            FROM SAR_net_errors $where $group_by;",
                'fields'    => array('rxerr/s', 'txerr/s', 'coll/s', 'rxdrop/s', 'txdrop/s', 'txcarr/s', 'rxfram/s', 'rxfifo/s', 'txfifo/s'),
                'title'     => "Network errors ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'bwm_in_out_total' => array(
                'metric'    => "Network",
                'query' => "SELECT  $aggr(`bytes_in`)/(1024*1024) `MB_in`,
                                    $aggr(`bytes_out`)/(1024*1024) `MB_out`
                                    FROM BWM2 $where_BWM AND iface_name = 'total' $group_by_BWM;",
                'fields'    => array('MB_in', 'MB_out'),
                'title'     => "BW Monitor NG Total Bytes IN/OUT ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'bwm_packets_total' => array(
                'metric'    => "Network",
                'query' => "SELECT  $aggr(`packets_in`) `packets_in`,
                                    $aggr(`packets_out`) `packets_out`
                                    FROM BWM2 $where_BWM AND iface_name = 'total' $group_by_BWM;",
                'fields'    => array('packets_in', 'packets_out'),
                'title'     => "BW Monitor NG Total packets IN/OUT ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),
            'bwm_errors_total' => array(
                'metric'    => "Network",
                'query' => "SELECT  $aggr(`errors_in`) `errors_in`,
                                    $aggr(`errors_out`) `errors_out`
                                    FROM BWM2 $where_BWM AND iface_name = 'total' $group_by_BWM;",
                'fields'    => array('errors_in', 'errors_out'),
                'title'     => "BW Monitor NG Total errors IN/OUT ($aggr_text, $hosts) $exec_title ",
                'percentage'=> false,
                'stacked'   => false,
                'negative'  => false,
            ),            
        );

        $has_records = false; //of any chart
        foreach ($charts[$exec] as $key_type=>$chart) {
            if ($chart['metric'] == 'ALL' || $metric == $chart['metric']) {
                $charts[$exec][$key_type]['chart'] = new HighCharts();
                $charts[$exec][$key_type]['chart']->setTitle($chart['title']);
                $charts[$exec][$key_type]['chart']->setPercentage($chart['percentage']);
                $charts[$exec][$key_type]['chart']->setStacked($chart['stacked']);
                $charts[$exec][$key_type]['chart']->setFields($chart['fields']);
                $charts[$exec][$key_type]['chart']->setNegativeValues($chart['negative']);

                list($rows, $max, $min) = minimize_exec_rows(get_rows($chart['query']), $chart['stacked']);

                if (!isset($chart_details[$key_type]['max']) || $max > $chart_details[$key_type]['max'])
                    $chart_details[$key_type]['max'] = $max;
                if (!isset($chart_details[$key_type]['min']) || $min < $chart_details[$key_type]['min'])
                    $chart_details[$key_type]['min'] = $min;

                //$charts[$exec][$key_type]['chart']->setMax($max);
                //$charts[$exec][$key_type]['chart']->setMin($min);

                if (count($rows) > 0) {
                    $has_records = true;
                    $charts[$exec][$key_type]['chart']->setRows($rows);
                }
            }
        }
    }

    if ($exec_details) {
        $max_time = null;
        foreach ($exec_details as $exec=>$exe_time) {
            if (!$max_time || $exe_time['time'] > $max_time) $max_time = $exe_time['time'];
        }
        foreach ($exec_details as $exec=>$exe_time) {
            #if (!$max_time) throw new Exception('Missing MAX time');
            $exec_details[$exec]['size'] = round((($exe_time['time']/$max_time)*100), 2);
            //TODO improve
            $exec_details[$exec]['max_time'] = $max_time;
        }
    }

    if (isset($has_records)) {

    } else {
        throw new Exception("No results for query!");
    }

} catch(Exception $e) {
    $message .= $e->getMessage()."\n";
}

?>

<?=make_HTML_header('Job details/s Execution details')?>
        <?=HighCharts::getHeader()?>
        <script>
            Highcharts.setOptions({
                colors: ["#7cb5ec", "#90ee7e", '#8085e9', "#DF5353", "#f7a35c", "#aaeeee",  "#55BF3B",  "#7798BF", "#aaeeee"]
                //colors: ['#7cb5ec','#434348','#90ed7d','#f7a35c','#8085e9','#f15c80','#e4d354','#8085e8','#8d4653','#91e8e1']
                //colors: ['#058DC7', '#50B432', '#ED561B', '#DDDF00', '#24CBE5', '#64E572', '#FF9655', '#FFF263', '#6AF9C4']
                //colors: ["#7cb5ec", "#90ee7e", "#7798BF", "#f7a35c", "#aaeeee", "#ff0066", "#eeaaee", "#55BF3B", "#DF5353", "#7798BF", "#aaeeee"]
                //colors: ["#DDDF0D", "#7798BF", "#55BF3B", "#DF5353", "#aaeeee", "#ff0066", "#eeaaee", "#55BF3B", "#DF5353", "#7798BF", "#aaeeee"]
                //colors: ["#DDDF0D", "#55BF3B", "#DF5353", "#7798BF", "#aaeeee", "#ff0066", "#eeaaee", "#55BF3B", "#DF5353", "#7798BF", "#aaeeee"]
                //colors: ["#f45b5b", "#8085e9", "#8d4654", "#7798BF", "#aaeeee", "#ff0066", "#eeaaee", "#55BF3B", "#DF5353", "#7798BF", "#aaeeee"]
            });

            $(document).ready(function() {
                $('select').change(function() {
                    $(this).parents('form').submit();
                });

<?php
if ($charts) {
    reset($charts);
    $current_chart = current($charts);

    foreach ($current_chart as $chart_type=>$chart) {
        foreach ($execs as $exec) {
            if (isset($charts[$exec][$chart_type]['chart'])) {
                //make Y axis all the same when comparing
                $charts[$exec][$chart_type]['chart']->setMax($chart_details[$chart_type]['max']);
                //the same for max X (plus 10%)
                $charts[$exec][$chart_type]['chart']->setMaxX(($exec_details[$exec]['max_time']*1.007));
                //print the JS
                echo $charts[$exec][$chart_type]['chart']->getChartJS()."\n\n";
            }
        }
    }
}
?>
            });
        </script>
<?php
echo make_header('HiBench Execution Performance Charts', $message);
echo make_navigation();
if ($charts) {
?>
    <form method="GET" >
            <div id="charts" style="width: 95%;">
                <div id="navigation" style="text-align: center;">
                    <h2>
                        <strong>Job/s details:</strong>
<!--                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="counters.php?type=SUMMARY--><?//=make_execs($execs)?><!--">Job Counters</a>-->
<!--                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="counters.php?type=HISTORY--><?//=make_execs($execs)?><!--">Job History</a>-->
<!--                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="counters.php?type=TASKS--><?//=make_execs($execs)?><!--">Job Tasks</a>-->
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="counters.php?type=TASKS<?=make_execs($execs)?>">Tasks & Counters</a>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<strong>Metrics:</strong>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="<?=modify_url(array('metric'=>'CPU'))?>"><?=($metric == 'CPU' ? '<strong>CPU</strong>':'CPU')?></a>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="<?=modify_url(array('metric'=>'Memory'))?>"><?=($metric == 'Memory' ? '<strong>Memory</strong>':'Memory')?></a>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="<?=modify_url(array('metric'=>'Network'))?>"><?=($metric == 'Network' ? '<strong>Network</strong>':'Network')?></a>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="<?=modify_url(array('metric'=>'Disk'))?>"><?=($metric == 'Disk' ? '<strong>Disk I/O</strong>':'Disk I/O')?></a>
                        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Buffers
                    </h2>
                    <div id="filters" style="text-align: right;">

                            <?php
                            foreach ($execs as $exec) {
                                echo '<input type="hidden" name="execs[]" value="'.$exec.'">';
                            }
                            ?>
                            <input type="hidden" name="metric" value="<?=$metric?>">
                            <strong>Filters:</strong> &nbsp;&nbsp;&nbsp;
                            Hosts <select name="hosts">
                                <?php
                                $host_rows = get_hosts($clusters);
                                echo '<option value="Slaves"'.(($hosts == "Slaves") ? ' SELECTED':'').'>All Slaves</option>';
                                echo '<option value="Master"'.(($hosts == "Master") ? ' SELECTED':'').'>Master</option>';
                                foreach ($host_rows as $host_row) {
                                    echo '<option value="'.$host_row['host_name'].'"'.(($hosts == $host_row['host_name']) ? ' SELECTED':'').'>'.$host_row['host_name'].'</option>';
                                }
                                ?>
                            </select>
                            &nbsp;&nbsp;&nbsp;
                            Aggregation <select name="aggr">
                                <?php
                                echo '<option value="AVG"'.(($aggr == "AVG") ? ' SELECTED':'').'>AVG</option>';
                                echo '<option value="SUM"'.(($aggr == "SUM") ? ' SELECTED':'').'>SUM</option>';
                                ?>
                            </select>
                            <!--<input type="submit" value="submit">-->
                    </div>

                </div>
                </br>
                <?php
                reset($charts);
                $current_chart = current($charts);
                foreach ($current_chart as $chart_type=>$chart) {
                    $first = true;
                    foreach ($execs as $exec) {
                        if (isset($charts[$exec][$chart_type]['chart'])) {
                            if ($first) {
                                echo '<div class="group_border">';
                                $first = false;
                            }
                            //echo $charts[$exec][$chart_type]['chart']->getContainer($exec_details[$exec]['size'])."\n\n";
                            echo $charts[$exec][$chart_type]['chart']->getContainer(100)."\n\n";
                            echo "<br/>";
                        }
                    }
                    if (!$first) echo '</div><br/>';
                }
                ?>
            </div>
    </form>
<!--    <div id="links_for_caching" style="color: lightgrey;">-->
<!--        Other links:-->
<!--        <a href="--><?//=modify_url(array('hosts'=>'Master'))?><!--" style="color: lightgrey;">Master</a>&nbsp;-->
<!--        --><?php
//        $host_rows = get_hosts($clusters);
//        foreach ($host_rows as $host_row) {
//            echo '<a href="'.modify_url(array('hosts'=>$host_row['host_name'])).'" style="color: lightgrey;">'.$host_row['host_name'].'</a>&nbsp;';
//        }
//        ?>
<!--        <a href="--><?//=modify_url(array('aggr'=>'SUM'))?><!--" style="color: lightgrey;">SUM</a>&nbsp;-->
<!--        </br></br>-->
<!--    </div>-->

<?php
}
echo $footer;