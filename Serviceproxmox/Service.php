<?php
/**
 * BoxBilling
 *
 * @copyright BoxBilling, Inc (http://www.boxbilling.com)
 * @license   Apache-2.0
 *
 * Copyright BoxBilling, Inc
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */
namespace Box\Mod\Serviceproxmox;
require_once 'pve2_api.class.php';

class Service implements \Box\InjectionAwareInterface
{
    protected $di;

    /**
     * @param mixed $di
     */
    public function setDi($di)
    {
        $this->di = $di;
    }

    /**
     * @return mixed
     */
    public function getDi()
    {
        return $this->di;
    }

    public function validateCustomForm(array &$data, array $product)
    {
        if ($product['form_id']) {

            $formbuilderService = $this->di['mod_service']('formbuilder');
            $form               = $formbuilderService->getForm($product['form_id']);
            foreach ($form['fields'] as $field) {
                if ($field['required'] == 1) {
                    $field_name = $field['name'];
                    if ((!isset($data[$field_name]) || empty($data[$field_name]))) {
                        throw new \Box_Exception("You must fill in all required fields. " . $field['label'] . " is missing", null, 9684);
                    }
                }

                if ($field['readonly'] == 1) {
                    $field_name = $field['name'];
                    if ($data[$field_name] != $field['default_value']) {
                        throw new \Box_Exception("Field " . $field['label'] . " is read only. You can not change its value", null, 5468);
                    }
                }
            }
        }

    }

	 /**
     * Method to install module. In most cases you will provide your own
     * database table or tables to store extension related data.
     *
     * If your extension is not very complicated then extension_meta
     * database table might be enough.
     *
     * @return bool
     * @throws \Box_Exception
     */
    public function install()
    {
        // execute sql script if needed
        $sql = "
        CREATE TABLE IF NOT EXISTS `service_proxmox_server` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `group` varchar(255) DEFAULT NULL,
            `ipv4` varchar(255) DEFAULT NULL,
            `ipv6` varchar(255) DEFAULT NULL,
            `hostname` varchar(255) DEFAULT NULL,
			`mac` varchar(255) DEFAULT NULL,
			`realm` varchar(255) DEFAULT NULL,
			`slots` bigint(20) DEFAULT NULL,
			`root_user` varchar(255) DEFAULT NULL,
            `root_password` varchar(255) DEFAULT NULL,
            `config` text,
			`active` bigint(20) DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;";
        $this->di['db']->exec($sql);
		
		$sql = "
        CREATE TABLE IF NOT EXISTS `service_proxmox` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
			`client_id` bigint(20) DEFAULT NULL,
			`order_id` bigint(20) DEFAULT NULL,
			`server_id` bigint(20) DEFAULT NULL,
			`node` varchar(255) DEFAULT NULL,
			`vmid` bigint(20) DEFAULT NULL,
			`ipv4` varchar(255) DEFAULT NULL,
            `ipv6` varchar(255) DEFAULT NULL,
			`hostname` varchar(255) DEFAULT NULL,
			`password` varchar(255) DEFAULT NULL,
            `config` text,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
			KEY `client_id_idx` (`client_id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;";
        $this->di['db']->exec($sql);

        //throw new \Box_Exception("Throw exception to terminate module installation process with a message", array(), 123);
        return true;
    }

    /**
     * Method to uninstall module.
     *
     * @return bool
     * @throws \Box_Exception
     */
    public function uninstall()
    {
		$this->di['db']->exec("DROP TABLE IF EXISTS `service_proxmox`");
		$this->di['db']->exec("DROP TABLE IF EXISTS `service_proxmox_server`");
        //throw new \Box_Exception("Throw exception to terminate module uninstallation process with a message", array(), 124);
        return true;
    }
	
    /**
     * @param \Model_ClientOrder $order
     * @return void
     */
    public function create($order)
    {
		$config = json_decode($order->config, 1);
        //$this->validateOrderData($config);
		/*
		    public function validateOrderData(array &$data)
			{
				if(!isset($data['server_id'])) {
					throw new \Box_Exception('Hosting product is not configured completely. Configure server for hosting product.', null, 701);
				}
				if(!isset($data['hosting_plan_id'])) {
					throw new \Box_Exception('Hosting product is not configured completely. Configure hosting plan for hosting product.', null, 702);
				}
				if(!isset($data['sld']) || empty($data['sld'])) {
					throw new \Box_Exception('Domain name is not valid.', null, 703);
				}
				if(!isset($data['tld']) || empty($data['tld'])) {
					throw new \Box_Exception('Domain extension is not valid.', null, 704);
				}
			}
		*/
		
        $product = $this->di['db']->getExistingModelById('Product', $order->product_id, 'Product not found');

        $model                	= $this->di['db']->dispense('service_proxmox');
        $model->client_id     	= $order->client_id;
		$model->order_id     	= $order->id;
        $model->created_at    	= date('Y-m-d H:i:s');
        $model->updated_at    	= date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return $model;
    }

    /**
     * @param \Model_ClientOrder $order
     * @return boolean
     */
    public function activate($order, $model)
    {
        if (!is_object($model)) {
            throw new \Exception('Could not activate order. Service was not created');
        }
		$config = json_decode($order->config, 1);
        //$this->validateOrderData($c);//
        $client  = $this->di['db']->load('client', $order->client_id);
        $product = $this->di['db']->load('product', $order->product_id);
		$service = $this->di['db']->dispense('service_proxmox');
        if (!$product) {
            throw new \Exception('Could not activate order because ordered product does not exists');
        }
		$product_config = json_decode($product->config, 1);
		
		// Allocate to an appropriate server id
		$serverid = $this->find_empty($product);
		
		// Retrieve server info
		$server = $this->di['db']->load('service_proxmox_server', $serverid);
		
		// Connect to Proxmox API
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, "pam", $server->root_password);
		
		// Create Proxmox VM
		if ($proxmox->login()) {
	
			//$proxmox->delete("/nodes/".$model->node."/qemu/".$model->vmid);
			//return var_export($promox, true);
			
			// Retrieve next VMID available
			$vmid = $proxmox->get("/cluster/nextid");
			$nodes = $proxmox->get_node_list();
			$first_node = $nodes[0];
			$proxmoxuser_password = $this->di['tools']->generatePassword(10, 4); // Generate password

			// Create Server
			$clone = '';
			$container_settings = array();
			$description = 'Server package '.$service->id.' belonging to '.$client->first_name.' '.$client->last_name.' id: '.$client->id;
			
			if ($product_config['clone'] == true) {
				$clone = '/'.$product_config['cloneid'].'/clone'; // Define the route for cloning
				$container_settings = array(
					'newid' => $vmid,
					'name' => $model->username,
					'description' => $description,
					'full' => true
				);		
			}
			else {
				if ($product_config['virt'] == 'qemu') {
					$container_settings = array(
						'vmid' => $vmid,
						'name' => $model->username,                 // Hostname to define
						'description' => $description,
						'storage' => $product_config['storage'],
						'memory' => $product_config['memory'],
						'ostype' => 'other',
						'ide2' => $product_config['cdrom'].',media=cdrom',
						'sockets' => $product_config['cpu'],
						'cores' => $product_config['cpu'],
						'ide0' => $product_config['ide0']
					);
				}
				else {
					$container_settings = array(
						'vmid' => $vmid,
						'hostname' => $model->username,             // Hostname to define
						'description' => $description,
						'storage' => $product_config['storage'],
						'memory' => $product_config['memory'],
						'ostemplate' => $product_config['ostemplate'],
						'password' => $proxmoxuser_password,
						'net0' => $product_config['network']
					);
					// Storage to do for LXC
				}
			}
			
			// If the VM is properly created
			if($proxmox->post("/nodes/".$first_node."/".$product_config['virt'].$clone, $container_settings)) {
				
				// Start the server
				sleep(20);
				$proxmox->post("/nodes/".$first_node."/".$product_config['virt']."/".$vmid."/status/start");
				$status = $proxmox->get("/nodes/".$first_node."/".$product_config['virt']."/".$vmid."/status/current");
				
				if(!empty($status)){
					sleep(10);
					// Wait until it has been started
					while ($status['status'] != 'running') {
						$proxmox->post("/nodes/".$first_node."/".$product_config['virt']."/".$vmid."/status/start");
						sleep(10);
						$status = $proxmox->get("/nodes/".$first_node."/".$product_config['virt']."/".$vmid."/status/current");
						// Starting twice => error...
					}
					// Debug 
					
					// Retrieve IP
					
					
			// USER TO BE DONE: not working yet
				
					/*$user_settings = array(
						'email' => $client->email,
						'firstname' => $client->first_name,
						'lastname' => $client->last_name,
						//'password' => $proxmoxuser_password		// Not working
					);
					
					// If proxmox user exists, delete it first
					if($proxmox->get("/access/users/".$client->id.'@'.$server->realm)) {
						//Update user information
						if(!$proxmox->delete("/access/users/".$client->id.'@'.$server->realm)) {
							throw new \Exception("Proxmox user exists but could not be deleted");
						}
					}
					
					// Create a Proxmox user for SSH access and server commands
					$user_settings['userid'] = $client->id.'@'.$server->realm;
					if(!$proxmox->post("/access/users", $user_settings)) {
						throw new \Exception("Proxmox user not created");
					}

					// Give correct permissions
					$permission_settings = array(
						'path' => '/vms/'.$vmid,
						'roles' => 'Dedicated',
						'users' => $client->id.'@'.$server->realm
					);
					if(!$proxmox->put("/access/acl", $permission_settings)) {
						throw new Exception("Proxmox permissions not added");
					}
					
					// Update password: not working yet...
					/*$user_settings = array(
						'password' => $proxmoxuser_password,
						'userid' => $client->id.'@'.$server->realm
					);
					if(!$proxmox->put("/access/password", $user_settings)) {
						throw new \Exception("Proxmox user password not updated");
					}*/
					
				
				}
				else {
					throw new \Exception("VMID cannot be found");
				}
			}
			else {
				throw new \Exception("VPS has not been created");
			}
		}
		else {
			throw new \Exception('Login to Proxmox Host failed', null, 7457);
		}

		// Retrieve VM IP
		
		$model->server_id 		= $serverid;
		$model->updated_at    	= date('Y-m-d H:i:s');
		$model->vmid			= $vmid;
		$model->node			= $first_node;
		$model->password		= $proxmoxuser_password;
		//$model->ipv4			= $ipv4;      	//How to do that?
		//$model->ipv6			= $ipv6;		//How to do that?
		//$model->hostname		= $hostname;	//How to do that?
		$this->di['db']->store($model);
			
		return array(
			'ip'	=> 	'to be sent by us shortly',		// Return IP address of the VM?
            'username'  =>  'root',
            'password'  =>  $proxmoxuser_password,
        );
    }

    /**
     * Suspend Proxmox VM
     * @param $order
     * @return boolean
     */
    public function suspend($order, $model)
    {
		// Shutdown VM
		$this->vm_shutdown($order, $model);
		// need to check that the VM was shutdown
		
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    /**
	 * Unsuspend Proxmox VM
     * @param $order
     * @return boolean
     */
    public function unsuspend($order, $model)
    {
		// Power on VM?

        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return true;
    }

    /**
	 * Cancel Proxmox VM
     * @param $order
     * @return boolean
     */
    public function cancel($order, $model)
    {
        return $this->suspend($order, $model);
    }

    /**
	 * Uncancel Proxmox VM
     * @param $order
     * @return boolean
     */
    public function uncancel($order, $model)
    {
        return $this->unsuspend($order, $model);
    }

    /**
	 * Delete Proxmox VM
     * @param $order
     * @return boolean
     */
    public function delete($order, $model)
    {
        if (is_object($model)) {
			
			$product = $this->di['db']->load('product', $order->product_id);
			$product_config = json_decode($product->config, 1);
			
			// Retrieve associated server
			$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$model->server_id));
            
			// Connect to YNH API
			$serveraccess = $this->find_access($server);
			$proxmox = new PVE2_API($serveraccess, $server->root_user, "pam", $server->root_password);
			
			if ($proxmox->login()) {
				$proxmox->post("/nodes/".$model->node."/".$product_config['virt']."/".$model->vmid."/status/shutdown");
				$status = $proxmox->get("/nodes/".$model->node."/".$product_config['virt']."/".$model->vmid."/status/current");
			
				// Wait until the server has been shut down if the server exists
				if(!empty($status)){
					while ($status['status'] != 'stopped') {
						sleep(10);
						$proxmox->post("/nodes/".$model->node."/".$product_config['virt']."/".$model->vmid."/status/shutdown");
						$status = $proxmox->get("/nodes/".$model->node."/".$product_config['virt']."/".$model->vmid."/status/current");
					}
				}
				else {
					throw new \Exception("VMID cannot be found");
				}
				if($proxmox->delete("/nodes/".$model->node."/".$product_config['virt']."/".$model->vmid))
				{
					// Delete Proxmox user if it exists : TO BE DONE
					/*if($proxmox->get("/access/users/".$client->id.'@'.$server->realm)) {
						//Update user information
						if(!$proxmox->delete("/access/users/".$client->id.'@'.$server->realm)) {
							throw new \Exception("Proxmox user exists but could not be deleted");
						}
					}*/
					return true;
				}
				else {
					throw new \Exception("VM not deleted");
				}
			} else {
				throw new \Exception("Login to Proxmox Host failed");
			}
		}
    }
	

    public function getConfig($model)
    {
        return $this->di['tools']->decodeJ($model->config);
    }

    public function toApiArray($model)
    {
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$model->server_id));
			
        return array(
            'id'              => $model->id,
            'client_id'       => $model->client_id,
            'server_id'       => $model->server_id,
            'username'        => $model->username,
            'mailbox_quota'   => $model->mailbox_quota,
			'server' 		  => $server,
        );
    }
	
	/*
		Change user password with $data['new-password']
	*/
	public function change_user_password($order, $model, $data)
	{
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$model->server_id));
		
		// Connect to Proxmox API
		$serveraccess = $this->find_access($server);
		// How to change Proxmox password?

	}
	
	/*
		Test connection
	*/
	public function test_connection($server)
	{
        // Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, "pam", $server->root_password);
		if ($proxmox->login()) {
			return true;
		}
		else {
			return false;
		}
	}
	

	/*
		Find empty slots
	*/
	public function find_empty($product)
    {
		$config = json_decode($product->config, 1);
		$group = $config['group'];
		$filling = $config['filling'];
		
		// Retrieve list of active server from this group
		// Retrieve the number of slots used per server
		if ($filling == 'least') {$condition = "ORDER BY ratio ASC";}
		else if ($filling == 'full') {$condition = "ORDER BY ratio DESC";}
		else {$condition = "";}
		
		// Retrieve only non-full active servers sorted by filling ratio (DESC for filling the least filled, ASC for filling servers up) - COALESC transforms null cells into zeros for calculations.
		$sql = "SELECT `server`.id, `server`.group, `server`.active, `server`.slots, COALESCE(`service`.used,0) as used, `server`.slots - COALESCE(`service`.used,0) as free, COALESCE(`service`.used,0) / `server`.slots as ratio
				FROM `service_proxmox_server` as `server`
				LEFT JOIN (
					SELECT COUNT(*) AS used, `service`.server_id
					FROM `service_proxmox` as `service`
					LEFT JOIN `client_order` ON `client_order`.service_id=`service`.id AND `client_order`.status = 'active'
				) as `service` ON `service`.server_id=`server`.id
				WHERE `server`.slots <> COALESCE(`service`.used,0) AND `server`.active=1 AND `server`.group='".$group."'
				".$condition." LIMIT 1";
				
        $appropriate_server = $this->di['db']->getAll($sql);
		if (!empty($appropriate_server[0]['id'])) {
			return $appropriate_server[0]['id'];
		}
		else {
			throw new \Exception('No server found');
			return false;
		}
	}
	
	/*
		Find access to server (hostname, ipv4, ipv6)
	*/
	public function find_access($server)
    {
		if (!empty($server->ipv6)) {return $server->ipv6;}
		else if (!empty($server->ipv4)) {return $server->ipv4;}
		else if (!empty($server->hostname)) {return $server->hostname;}
		else {
			throw new \Exception('No IPv6, IPv4 or Hostname found for server '.$server->id);
		}
	}
	
	/*
		VM status
		TO DO : add more infos
	*/
	public function vm_info($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
			
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$service->server_id));
	
        // Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, "pam", $server->root_password);
		if ($proxmox->login()) {
			$status = $proxmox->get("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/current");
			// VM monitoring?
			
			$output = array(
				'status'	=> $status['status']
			);
			return $output;
		}
		else {
			throw new \Exception("Login to Proxmox Host failed.");
		}
	}
	
	/*
		Reboot VM
	*/
	public function vm_reboot($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
			
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$service->server_id));
	
        // Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, "pam", $server->root_password);
		if ($proxmox->login()) {
			$proxmox->post("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/shutdown");
			$status = $proxmox->get("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/current");
			
			// Wait until the VM has been shut down if the VM exists
			if(!empty($status)){
				while ($status['status'] != 'stopped') {
					sleep(10);
					$proxmox->post("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/shutdown");
					$status = $proxmox->get("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/current");
				}
			}
			// Restart
			if($proxmox->post("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/start")) {
				sleep(10);
				$status = $proxmox->get("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/current");
				while ($status['status'] != 'running')
				{
						$proxmox->post("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/start");
						sleep(10);
						$status = $proxmox->get("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/current");
						// Starting twice => error...
				}
				return true;
			}
			else {
				throw new \Exception("Reboot failed");
			}
		}
		else {
			throw new \Exception("Login to Proxmox Host failed.");
		}
	}
	
	/*
		Start VM
	*/
	public function vm_start($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
			
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$service->server_id));
	
        // Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, "pam", $server->root_password);
		if ($proxmox->login()) {
			$proxmox->post("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/start");
			return true;
		}
		else {
			throw new \Exception("Login to Proxmox Host failed.");
		}
	}
	
	/*
		Shutdown VM
	*/
	public function vm_shutdown($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
			
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$service->server_id));
	
        // Test if login
		$serveraccess = $this->find_access($server);
		$proxmox = new PVE2_API($serveraccess, $server->root_user, "pam", $server->root_password);
		if ($proxmox->login()) {
			$settings = array(
				'forceStop' 	=> true
			);
		
			$proxmox->post("/nodes/".$service->node."/".$product_config['virt']."/".$service->vmid."/status/shutdown", $settings);
			return true;
		}
		else {
			throw new \Exception("Login to Proxmox Host failed.");
		}
	}
	
	
	/*
		VM iframe for Web CLI
	*/
	public function vm_cli($order, $service)
	{
		$product = $this->di['db']->load('product', $order->product_id);
		$product_config = json_decode($product->config, 1);
		$client  = $this->di['db']->load('client', $order->client_id);
			
		// Retrieve associated server
		$server = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$service->server_id));
	
        // Find access route
		$serveraccess = $this->find_access($server);
		
		// Setup console type
		if($product_config['virt']=='qemu') {$console='kvm';}
		else {$console='shell';}

		// The user enters the password to see the iframe: TBD
			//$password = 'test';
		//$proxmox = new PVE2_API($serveraccess, $client->id, $service->node, $password);
		
		$proxmox = new PVE2_API($serveraccess, $server->root_user, $server->realm, $server->root_password);
		if ($proxmox->login()) {
			$proxmox->setCookie();
			$cli = $console = '<iframe  src="https://'.$serveraccess.':8006/?console='.$console.'&novnc=1&vmid='.$service->vmid.'&node='.$service->node.'" frameborder="0" scrolling="no" width="100%" height="600px"></iframe>';
			return $cli;
		}
		else {
			throw new \Exception("Login to Proxmox VM failed.");
		}
	}
	
/*
TO BE DONE
	- Reboot server?
*/
}