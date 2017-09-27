<?php

declare(strict_types=1);
namespace IXP\Tasks\Yaml;

/*
 * Copyright (C) 2009-2016 Internet Neutral Exchange Association Company Limited By Guarantee.
 * All Rights Reserved.
 *
 * This file is part of IXP Manager.
 *
 * IXP Manager is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, version v2.0 of the License.
 *
 * IXP Manager is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License v2.0
 * along with IXP Manager.  If not, see:
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

use Entities\PhysicalInterface;
use Entities\Switcher as SwitchEntity;
use Entities\VirtualInterface as VirtualInterfaceEntity;
use Entities\PhysicalInterface as PhysicalInterfaceEntity;

use Illuminate\Contracts\View\View as ViewContract;

use IXP\Exceptions\GeneralException;

/**
 * ConfigurationGenerator
 *
 * @author     Barry O'Donovan <barry@islandbridgenetworks.ie>
 * @category   Tasks
 * @package    IXP\Tasks\Router
 * @copyright  Copyright (C) 2009-2016 Internet Neutral Exchange Association Company Limited By Guarantee
 * @license    http://www.gnu.org/licenses/gpl-2.0.html GNU GPL V2.0
 */
class SwitchConfigurationGenerator
{
    /**
     *
     * @var SwitchEntity
     */
    private $switch = null;

    public function __construct( SwitchEntity $switch ) {
        $this->setSwitch( $switch );
    }

    /**
     * Set the switch
     *
     * @param SwitchEntity $switch
     * @return SwitchConfigurationGenerator
     */
    public function setSwitch( SwitchEntity $switch ): SwitchConfigurationGenerator {
        $this->switch = $switch;
        return $this;
    }

    /**
     * Get the switch options array
     *
     * @return SwitchEntity
     */
    public function getSwitch(): SwitchEntity {
        return $this->switch;
    }

    private function template(): string {
        return 'api/v4/provisioner/yaml/switch';
    }

    /**
     * Generate and return the configuration
     *
     * @throws GeneralException
     * @return ViewContract The configuration
     */
    public function render(): ViewContract
    {

        $ports = [];
        $visProcessed = [];
        $cbsProcessed = [];

        foreach( $this->getSwitch()->getPorts() as $sp ) {

            /** @var \Entities\SwitchPort $sp */
            if( !$sp->isTypeUnset() && !$sp->isTypePeering() && !$sp->isTypeCore() ) {
                continue;
            }

            // is the port in use?
            if( !($pi = $sp->getPhysicalInterface()) ) {
                continue;
            }

            if( ( $sp->isTypeUnset() || $sp->isTypePeering() ) && !in_array($pi->getVirtualInterface()->getId(), $visProcessed)  ) {
                $ports = array_merge($ports, $this->processVirtualInterface($pi->getVirtualInterface()));
                $visProcessed[] = $pi->getVirtualInterface()->getId();
            } else if( $sp->isTypeCore() && !in_array($pi->getVirtualInterface()->getId(), $cbsProcessed ) && $pi->getCoreBundle()->isL2LAG() ) {
                $ports = array_merge( $ports, $this->processCoreBundleInterface( $pi ) );
                $cbsProcessed[] = $pi->getVirtualInterface()->getId();
            }
        }

        return view($this->template())->with(
            [ 'ports' => $ports ]
        );
    }

    private function processVirtualInterface( VirtualInterfaceEntity $vi ): array {

        $p                       = [];
        $p['type']               = 'edge';
        $p['description']        = $vi->getCustomer()->getAbbreviatedName();
        $p['dot1q']              = $vi->getTrunk() ? 'yes' : 'no';
        $p['virtualinterfaceid'] = $vi->getId();
        if( $vi->getChannelgroup() ) {
            $p['lagindex'] = $vi->getChannelgroup();
        }

        $p['vlans'] = [];

        foreach( $vi->getVlanInterfaces() as $vli ) {
            /** @var \Entities\VlanInterface $vli */
            $v = [];
            $v[ 'number' ] = $vli->getVlan()->getNumber();
            $v[ 'macaddresses' ] = [];
            foreach( $vli->getLayer2Addresses() as $mac ) {
                $v[ 'macaddresses' ][] = $mac->getMacFormattedWithColons();
            }
            $p[ 'vlans' ][] = $v;
        }

        // we now have the base port config. If this is not a LAG, just return it:
        if( !$vi->getLagFraming() ) {
            $pi = $vi->getPhysicalInterfaces()[0];
            $p['shutdown']           = ($pi->getStatus() == \Entities\PhysicalInterface::STATUS_CONNECTED) ? 'no' : 'yes';
            $p['name']               = $pi->getSwitchport()->getIfName();
            $p['speed']              = $pi->getSpeed();
            $p['autoneg']            = $pi->getAutoneg() ? 'yes' : 'no';
            return [ $p ];
        }

        $ports = [];

        // bundle definition:
        $p['name']      = $vi->getBundleName();
        $p['lagmaster'] = 'yes';
        $p['fastlacp']  = $vi->getFastLACP() ? 'yes' : 'no';
        $p['lagmembers']= [];
        $p['shutdown']  = 'yes';

        // build up list of physical ports associated with this lag master
        foreach( $vi->getPhysicalInterfaces() as $pi ) {
            if( !$pi->getSwitchPort() ) {
                continue;
            }
            $p['lagmembers'][]= $pi->getSwitchPort()->getIfName();

            // if any bundle members are up, the LAG is up
            if ($pi->getStatus() == \Entities\PhysicalInterface::STATUS_CONNECTED) {
                $p['shutdown'] = 'no';
            }
        }
        $ports[]        = $p;

        unset( $p['lagmembers'] );

        // interface definitions:
        foreach( $vi->getPhysicalInterfaces() as $pi ) {
            /** @var PhysicalInterfaceEntity $pi */
            if( !$pi->getSwitchPort() ) {
                continue;
            }
            $p['shutdown']  = ($pi->getStatus() == \Entities\PhysicalInterface::STATUS_CONNECTED) ? 'no' : 'yes';
            $p['name']      = $pi->getSwitchPort()->getIfName();
            $p['lagmaster'] = 'no';
            $p['autoneg']   = $pi->getAutoneg() ? 'yes' : 'no';
            $p['speed']     = $pi->getSpeed();
            $ports[] = $p;
        }

        return $ports;
    }

    private function processCoreBundleInterface( PhysicalInterfaceEntity $pi ): array {

        $vi = $pi->getVirtualInterface();
        $ci = $pi->getCoreInterface();
        $cl = $ci->getCoreLink();
        $cb = $cl->getCoreBundle();

        // side a or side b?
        if( $cl->getCoreInterfaceSideA()->getId() == $ci->getId() ) {
            $sideFn = 'getCoreInterfaceSideA';
        } else {
            $sideFn = 'getCoreInterfaceSideB';
        }

        $p                       = [];
        $p['type']               = 'core';
        $p['description']        = $cb->getDescription();
        $p['dot1q']              = $vi->getTrunk() ? 'yes' : 'no';
        $p['stp']                = $cb->getSTP() ? 'yes' : 'no';
        $p['cost']               = $cb->getCost();
        $p['preference']         = $cb->getPreference();
        $p['virtualinterfaceid'] = $vi->getId();
        $p['corebundleid']       = $cb->getId();
        if( $vi->getChannelgroup() ) {
            $p['lagindex'] = $vi->getChannelgroup();
        }

        $p['vlans'] = [];

        foreach( $pi->getSwitchPort()->getSwitcher()->getInfrastructure()->getVlans() as $vlan ) {
            $v = [];
            $v[ 'number' ] = $vlan->getNumber();
            $v[ 'macaddresses' ] = [];
            $p[ 'vlans' ][] = $v;
        }

        // we now have the base port config. If this is not a LAG, just return it:
        if( !$vi->getLagFraming() ) {
            $p['shutdown']           = ($pi->getStatus() == \Entities\PhysicalInterface::STATUS_CONNECTED) ? 'no' : 'yes';
            $p['name']               = $pi->getSwitchport()->getIfName();
            $p['speed']              = $pi->getSpeed();
            $p['autoneg']            = $pi->getAutoneg() ? 'yes' : 'no';
            return [ $p ];
        }

        $ports = [];

        // bundle definition:
        $p['name']      = $vi->getBundleName();
        $p['lagmaster'] = 'yes';
        $p['fastlacp']  = $vi->getFastLACP() ? 'yes' : 'no';
        $p['lagmembers']= [];
        $p['shutdown']  = 'yes';

        // build up list of physical ports associated with this lag master
        foreach( $cb->getCoreLinks() as $_cl ) {
            $_pi = $_cl->$sideFn()->getPhysicalInterface();
            if( !$_pi->getSwitchPort() ) {
                continue;
            }
            $p['lagmembers'][]= $_pi->getSwitchPort()->getIfName();

            // if any bundle members are up, the LAG is up
            if( $_pi->getStatus() == \Entities\PhysicalInterface::STATUS_CONNECTED ) {
                $p['shutdown'] = 'no';
            }
        }

        // but if the bundle is down, the whole lot is down
        if( !$cb->getEnabled() ) {
            $p['shutdown'] = 'yes';
        }

        $ports[]        = $p;

        unset( $p['lagmembers'] );

        // interface definitions:
        foreach( $cb->getCoreLinks() as $_cl ) {
            $_pi = $_cl->$sideFn()->getPhysicalInterface();

            /** @var PhysicalInterfaceEntity $_pi */
            if( !$_pi->getSwitchPort() ) {
                continue;
            }
            $p['shutdown']  = ($_pi->getStatus() == \Entities\PhysicalInterface::STATUS_CONNECTED) ? 'no' : 'yes';
            $p['name']      = $_pi->getSwitchPort()->getIfName();
            $p['lagmaster'] = 'no';
            $p['autoneg']   = $_pi->getAutoneg() ? 'yes' : 'no';
            $p['speed']     = $_pi->getSpeed();
            $ports[] = $p;
        }

        return $ports;
    }
}
