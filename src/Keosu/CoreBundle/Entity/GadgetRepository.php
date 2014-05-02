<?php
/************************************************************************
 Keosu is an open source CMS for mobile app
Copyright (C) 2014  Vincent Le Borgne, Pockeit

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as
published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
************************************************************************/
namespace Keosu\CoreBundle\Entity;
use Doctrine\ORM\EntityRepository;

/**
 * GadgetRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class GadgetRepository extends EntityRepository {
	
	/***
	 * Find all shared gadget in a zone for an app
	 */
	public function findSharedByZoneAndApp($zone, $appid ) {
		$gadgets = $this->_em
			->createQuery(
				'SELECT DISTINCT g FROM Keosu\CoreBundle\Entity\Page a JOIN Keosu\CoreBundle\Entity\Gadget g
					WITH a.appId='.$appid.' WHERE g.shared=1 AND g.zone=\'' . $zone.'\'')->getResult();	
		
		if (count($gadgets) == 0 ) {
			return null;
		}
		//TODO : fix the query to evoid this
		foreach($gadgets as $gadget){
			if($gadget->getPage()->getAppId()==$appid){
				return $gadget;
			}
		}
		return null; 
	}
}