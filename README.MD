# Proxmox VM provisioning for BoxBilling

**WARNING: ALPHA VERSION. USE AT YOUR OWN RISKS. See Issues for the work still to be done**

This is a BoxBilling module to provision Virtual Machines (VMs) using Proxmox.

Please report issues and suggestions within this git. The theme is licensed under GPLv3 so please feed back your improvements to this git.


## Features
- Manage pools of Proxmox servers (orders can be allocated to servers automatically based on their capacity)
- Provision LXC containers
- Provision QEMU KVM machines
- Clients can use an online console, start, shutdown and reboot their VMs


![LXC dashboard](https://framapic.org/Czs2kvia9MVM/LVLHPyom7HWe.JPG)

![QEMU dashboard](https://framapic.org/bcAMQ6sksIEz/632Eg9fKkyW5.JPG)


The screenshots are done using my [Bootstrap theme!](https://github.com/scith/boxbilling-bootstrap)!


## Installation
- Copy the "Serviceproxmox" folder in *bb-modules*
- Add new Proxmox servers
- Add new Proxmox products with the correct VM settings setup

![Server list](https://framapic.org/woQzf9siUbSR/b2GUnejy28UE)

![Product settings](https://framapic.org/XlgIF5M7I2CE/OLEiP5eEw1nW)


## Not working yet
- *See Issues*