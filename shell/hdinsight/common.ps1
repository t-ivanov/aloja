function AzureLogin([String]$credentialsFile)
{
  Import-AzurePublishSettingsFile $credentialsFile
}

function SelectSubscription([String]$subscriptionName)
{
   Select-AzureSubscription -Current $subscriptionName
}

function DeleteStorageFile([String]$fileToDelete,[String]$storageAccount, [String]$storageKey, [String]$containerName) {
  $context = New-AzureStorageContext -StorageAccountName $storageAccount -StorageAccountKey $storageKey
  $blob = Get-AzureStorageBlob -Container $containerName -Blob $fileToDelete -Context $context -ErrorVariable blobExist -ErrorAction silentlyContinue | Out-null
  if($blobExist.Exception -eq $null) {
    $blob | %{ Remove-AzureStorageBlob -Blob $_.Name -Container $containerName -Context $context }
    Write-Verbose "Blob removed"
  }
  else {
    Write-Verbose "$fileToDelete does not exist in storage container!"
	Write-Verbose "Error: $blobExist"
  }
}

function RunBench($definition, $containerName, $reduceTasks, $benchName = "terasort") {
   $result = Test-Path $containerName
   if(!$result) {
      mkdir $containerName
   }
   
   $directoryName = $benchName + "_r_$reduceTasks"
   $result = Test-Path $containerName/$directoryName
   if(!$result) {
     mkdir $containerName/$directoryName
   }
 
   Write-Verbose "Start running benchmark"
   $definition | Start-AzureHDInsightJob -Cluster $clusterName | Wait-AzureHDInsightJob -WaitTimeoutInSeconds 100000 | %{ Get-AzureHDInsightJobOutput -Cluster $clusterName -JobId $_.JobId -StandardError -StandardOutput > $_.JobId }
   mv job_* $containerName/$directoryName/
   Write-Verbose "Completed"
}

function RetrieveData([String]$storageAccount, [String]$storageContainer, [String]$logsDir, [String]$storageKey, [String]$minervaLogin) {
   rm $logsDir -R
   mkdir $logsDir
   Write-Verbose "Copying from storage blob"
   AzCopy /Source:"https://$storageAccount.blob.core.windows.net/$storageContainer" /Dest:$logsDir /SourceKey:"$storageKey" /S /Pattern:mapred /Y
   AzCopy /Source:"https://$storageAccount.blob.core.windows.net/$storageContainer" /Dest:$logsDir /SourceKey:"$storageKey" /S /Pattern:app-logs /Y
   AzCopy /Source:"https://$storageAccount.blob.core.windows.net/$storageContainer" /Dest:$logsDir /SourceKey:"$storageKey" /S /Pattern:yarn /Y
   Write-Verbose "Copying job logs to logs dir"
   cp -R $storageContainer $logsDir/
   Write-Verbose "Copying to minerva account"
   $date = date +%h-%m-%s
   scp -r "$logsDir" "$minervaLogin@minerva.bsc.es:~/hdplogs$storageAccount$date"
   Write-Verbose "Retrieval and saving of logs completed"
}

function createAzureStorageContainer([String]$storageName, [String]$storageKey, [String]$containerName) {
   $context = New-AzureStorageContext -StorageAccountName $storageAccount -StorageAccountKey $storageKey
   New-AzureStorageContainer -Context $context -Name $containerName
}

function removeAzureStorageContainer([String]$storageName, [String]$storageKey, [String]$containerName) {
	 $context = New-AzureStorageContext -StorageAccountName $storageAccount -StorageAccountKey $storageKey
	 Remove-AzureStorageContainer -Name $containerName -Context $context -Force
}

function createCluster([String]$clusterName, [Int32]$nodesNumber=16, [String]$storageName, [String]$storageKey, [bool]$createContainer=$True, [String]$containerName = $null, [String]$subscriptionName, [System.Management.Automation.PsCredential]$cred) {
   if($containerName -eq $null) {
     $containerName = $storageName
   }
   
   if($createContainer) {
     Write-Verbose "Creating container $containerName to storage $storageName"
     createAzureStorageContainer -storageName $storageName -storageKey $storageKey -containerName $containerName
   }
   Write-Verbose "Storage container assigned to cluster"
   
   Write-Verbose "Creating HDInsight cluster"
   New-AzureHDInsightCluster -Name $clusterName -ClusterSizeInNodes $nodesNumber -Location "West Europe" -Credential $cred -DefaultStorageAccountKey $storageKey -DefaultStorageAccountName "$storageName.blob.core.windows.net" -DefaultStorageContainerName $containerName
   Write-Verbose "HDInsight cluster created successfully"
}

function destroyCluster([String]$clusterName, [String]$storageName, [String]$storageKey, [bool]$destroyContainer=$True, [String]$containerName=$null, [String]$subscriptionName) {
  if($destroyContainer -eq $True) {
     if($containerName -eq $null) {
	    $containerName = $storageName
	 }
	 Write-Verbose "Removing azure storage container"
	 removeAzureStorageContainer -StorageName $storageName -StorageKey $storageKey -ContainerName $containerName
  }
  
  Write-Verbose "Removing HDInsight cluster"
  Remove-AzureHDInsightCluster -Name $clusterName
  Write-Verbose "HDinsight cluster removed successfully"
}
