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

namespace Box\Mod\Serviceproxmox\Api;

/**
 * Hosting service management 
 */
class Admin extends \Api_Abstract
{  
    /**
     * Get list of servers
     * 
     * @return array
     */
    public function server_get_list($data)
    {
		$servers = $this->di['db']->find('service_proxmox_server');
		$servers_grouped = array();
        foreach ($servers as $server) {
			$servers_grouped[$server['group']]['group'] = $server->group;
			$servers_grouped[$server['group']]['servers'][$server['id']] = array(
				'id'			=> $server->id,
				'name' 			=> $server->name,
				'group' 		=> $server->group,
				'ipv4' 			=> $server->ipv4,
				'ipv6' 			=> $server->ipv6,
				'hostname' 		=> $server->hostname,
				'access' 		=> $this->getService()->find_access($server),
				'used_slots'	=> $server->slots - $this->used_slots($server->id),
				'slots' 		=> $server->slots,
				'active'		=> $server->active,
			);
        }
        return $servers_grouped;
    }

	/* Get server details from order id */
	public function server_get_from_order($data)
    {
        $required = array(
            'order_id'    => 'Order id is missing',
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

		// Retrieve order
		if(!isset($data['order_id'])) {
            throw new \Exception('Order id is required');
        }
        $service = $this->di['db']->findOne('service_proxmox',
                "order_id=:id", 
                array(':id'=>$data['order_id']));
        if(!$service) {
			$data = array ('server_id' => $service['server_id']);
			return null;
        }
		$data = array ('server_id' => $service['server_id']);
		$output = $this->server_get($data);
        return $output;
    }
	
    /**
     * Get server details
     * 
     * @param int $id - server id
     * @return array
     * 
     * @throws \Box_Exception 
     */
    public function server_get($data)
    {
		// Retrieve associated server
		$server  = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$data['server_id']));

		//TODO: Update settings
		$output = array(
			'id'			=> $server->id,
			'name' 			=> $server->name,
			'group' 		=> $server->group,
			'ipv4' 			=> $server->ipv4,
			'ipv6' 			=> $server->ipv6,
			'hostname' 		=> $server->hostname,
			//'access' 		=> $this->getService()->find_access($server),
			//'used_slots'	=> $server->slots - $this->used_slots($server->id),
			'slots' 		=> $server->slots,
			'root_user' 	=> $server->root_user,
			'root_password' => $server->root_password,
			'admin_password'=> $server->admin_password,
			'active'		=> $server->active,
		);
        return $output;
    }
    
    /*
		Update product informations
	*/
    public function product_update($data)
    {
        $required = array(
			'id'			=> 'Product id is missing',
            'group'    		=> 'Server group is missing',
            'filling'   	=> 'Filling method is missing',
			'show_stock'    => 'Stock display is missing',
			'virt'			=> 'Virtualization type is missing',
			'storage'		=> 'Target storage is missing',
			'memory'		=> 'Memory is missing',
			'cpu'			=> 'CPU cores is missing',
			'network'		=> 'Network net0 is missing',
			'ide0'			=> 'Storage ide0 is missing',
			'clone'			=> 'Clone info is missing'
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

		// Retrieve associated product
		$product  = $this->di['db']->findOne('product','id=:id',array(':id'=>$data['id']));
		
		$config = array(
			'group'			=> $data['group'],
			'filling'		=> $data['filling'],
			'show_stock'	=> $data['show_stock'],
			'virt'			=> $data['virt'],
			'storage'		=> $data['storage'],
			'ostemplate'	=> $data['ostemplate'],
			'cdrom'			=> $data['cdrom'],
			'memory'		=> $data['memory'],
			'cpu'			=> $data['cpu'],
			'network'		=> $data['network'],
			'ide0'			=> $data['ide0'],
			'clone'			=> $data['clone'],
			'cloneid'		=> $data['cloneid']
		);
		
		$product->config     	= json_encode($config);
        $product->updated_at    = date('Y-m-d H:i:s');
        $this->di['db']->store($product);
		
		$this->di['logger']->info('Update Proxmox product %s', $product->id);
        return true;
    }
	
	/**
     * Create new hosting server 
     * 
     * @param string $name - server name
     * @param string $ip - server ip
     * @optional string $hostname - server hostname
     * @optional string $username - server API login username
     * @optional string $password - server API login password
     * @optional bool $active - flag to enable/disable server
     * 
     * @return int - server id 
     * @throws \Box_Exception 
     */
    public function server_create($data)
    {
        $required = array(
            'name'    => 'Server name is missing',
            'slots'      => 'Slots are missing',
			'root_user'      => 'Root user is missing',
			'root_password'      => 'Root password is missing',
			'realm'      => 'Proxmox user realm is missing',
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

		$server 				= $this->di['db']->dispense('service_proxmox_server');
        $server->name     		= $data['name'];
		$server->group     		= $data['group'];
		$server->ipv4     		= $data['ipv4'];
		$server->ipv6     		= $data['ipv6'];
		$server->hostname     	= $data['hostname'];
		$server->realm     		= $data['realm'];
		$server->slots     		= $data['slots'];
		$server->root_user     	= $data['root_user'];
		$server->root_password	= $data['root_password'];
		$server->config     	= $data['config'];
		$server->active     	= $data['active'];
        $server->created_at    	= date('Y-m-d H:i:s');
        $server->updated_at    	= date('Y-m-d H:i:s');
        $this->di['db']->store($server);
		
		$this->di['logger']->info('Create Proxmox server %s', $server->id);
		
		return true;
    }
	
    /**
     * Delete server
     * 
     * @param int $id - server id
     * @return boolean
     * @throws \Box_Exception 
     */
    public function server_delete($data)
    {
        $required = array(
            'id'    => 'Server id is missing',
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);
        $server = $this->di['db']->getExistingModelById('service_proxmox_server', $data['id'], 'Server not found');
		$this->di['db']->trash($server);
    }

    /**
     * Update server configuration
     * 
     * @param int $id - server id
     * 
     * @optional string $hostname - server hostname
     * @optional string $username - server API login username
     * @optional string $password - server API login password
     * @optional bool $active - flag to enable/disable server
     * 
     * @return boolean
     * @throws \Box_Exception 
     */
    public function server_update($data)
    {
		$required = array(
            'name'    => 'Server name is missing',
            'slots'      => 'Slots are missing',
			'root_user'      => 'Root user is missing',
			'root_password'      => 'Root password is missing',
			'realm'      => 'Proxmox user realm is missing',
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

		// Retrieve associated server
		$server  = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$data['server_id']));
		
        $server->name     		= $data['name'];
		$server->group     		= $data['group'];
		$server->ipv4     		= $data['ipv4'];
		$server->ipv6     		= $data['ipv6'];
		$server->hostname     	= $data['hostname'];
		$server->realm     		= $data['realm'];
		$server->slots     		= $data['slots'];
		$server->root_user     	= $data['root_user'];
		$server->root_password	= $data['root_password'];
		$server->config     	= $data['config'];
		$server->active     	= $data['active'];
        $server->created_at    	= date('Y-m-d H:i:s');
        $server->updated_at    	= date('Y-m-d H:i:s');
        $this->di['db']->store($server);
		
		$this->di['logger']->info('Update Proxmox server %s', $server->id);
        return true;
	
        /*$required = array(
            'server_id'    => 'Server id is missing',
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

		// Retrieve associated server
		$server  = $this->di['db']->findOne('service_proxmox_server','id=:id',array(':id'=>$data['server_id']));
		
		// Update server
		$service = $this->getService();
		return (bool) $service->updateServer($model, $data);*/
    }

    /**
     * Test connection to server
     * 
     * @param int $id - server id
     * 
     * @return bool
     * @throws \Box_Exception 
     */
    public function server_test_connection($data)
    {
        $required = array(
            'id'    => 'Server id is missing',
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);

        $server = $this->di['db']->getExistingModelById('service_proxmox_server', $data['id'], 'Server not found');
        return (bool) $this->getService()->test_connection($server);
    }
	
	/*
		Get list of server groups
     */
    public function server_groups()
    {
        $server = $this->di['db']->dispense('service_proxmox_server');
		$sql = "SELECT DISTINCT `group` FROM `service_proxmox_server` WHERE `active` = 1";
        $groups = $this->di['db']->getAll($sql);
		return $groups;
    }
	
	/*
		Retrieve empty slots for each server
	*/
	public function used_slots($server_id)
	{
		$sql = "SELECT `service_proxmox`.server_id, COUNT(*) AS used
				FROM `client_order` 
				INNER JOIN `service_proxmox` ON `client_order`.service_id=`service_proxmox`.id 
				WHERE `client_order`.service_type = 'proxmox' AND `client_order`.status = 'active' AND `service_proxmox`.server_id = ".$server_id."
				GROUP BY `service_proxmox`.server_id";
        $active_orders = $this->di['db']->getAll($sql);
		if(empty($active_orders[0]['used'])) {$active_orders[0]['used']=0;}
		return $active_orders[0]['used'];
	}

    public function _getService($data)
    {
        /*$required = array(
            'order_id'    => 'Order ID name is missing',
        );
        $this->di['validator']->checkRequiredParamsForArray($required, $data);
        
        $order = $this->di['db']->getExistingModelById('ClientOrder', $data['order_id'], 'Order not found');
        $orderSerivce = $this->di['mod_service']('order');
        $s = $orderSerivce->getOrderService($order);
        if(!$s instanceof \Model_Serviceproxmox) {
            throw new \Box_Exception('Order is not activated');
        }
        return array($order, $s);*/
    }
}