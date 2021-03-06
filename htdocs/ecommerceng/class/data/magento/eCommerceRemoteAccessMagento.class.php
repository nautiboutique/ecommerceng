<?php
/* Copyright (C) 2010 Franck Charpentier - Auguria <franck.charpentier@auguria.net>
 * Copyright (C) 2013 Laurent Destailleur          <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

dol_include_once('/ecommerceng/admin/class/data/eCommerceDict.class.php');
dol_include_once('/ecommerceng/class/data/eCommerceCategory.class.php');
dol_include_once('/ecommerceng/class/data/eCommerceSociete.class.php');


/**
 * Class for access remote sites
 */
class eCommerceRemoteAccessMagento
{

    private $site;
    private $session;
    private $client;
    private $filter;
    private $taxRates;
    private $db;

    /**
     *      Constructor
     *      @param      DoliDB      $db         Database handler
     *      @param      string      $site       eCommerceSite
     */
    function eCommerceRemoteAccessMagento($db, $site)
    {
        $this->db = $db;
        $this->site = $site;
        return 1;
    }

    /**
     * Connect to API
     * 
     * @return boolean      True if OK, False if KO
     */
    public function connect()
    {
        try {
            require_once(DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php');
            $params=getSoapParams();
            @ini_set('default_socket_timeout', $params['response_timeout']);
            @ini_set("memory_limit", "1024M");
            
            // To force non cache even when enabled
            if (! empty($conf->global->ECOMMERCE_SOAP_FORCE_NO_CACHE))
            {
                ini_set("soap.wsdl_cache_enabled", "0");
                $params['cache_wsdl']=WSDL_CACHE_NONE;
            }
            
            /*var_dump($params);
            var_dump($this->site->webservice_address);
            include DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
            $aaa=getURLContent('http://pchome-ld.hd.free.fr:801/magento/index.php/api/?wsdl');
            var_dump($aaa);
            exit;*/
            
            //dol_syslog("eCommerceRemoteAccessMagento Connect to API webservice_address=".$this->site->webservice_address." user_name=".$this->site->user_name." user_password=".preg_replace('/./','*',$this->site->user_password));
            dol_syslog("eCommerceRemoteAccessMagento Connect to API webservice_address=".$this->site->webservice_address." user_name=".$this->site->user_name." user_password=".$this->site->user_password);
            
            // TODO Add option to manage mode "non WSDL". location and uri should be set on $params.
            $this->client = new SoapClient($this->site->webservice_address, $params);
            
            dol_syslog("eCommerceRemoteAccessMagento new SoapClient ok. Now we call SOAP login method");
            
            //xdebug_disable();
            $this->session = $this->client->login($this->site->user_name, $this->site->user_password);
            //xdebug_enable();
            
            dol_syslog("eCommerceRemoteAccessMagento connected with session=".$this->session);
            
            return true;
        }
        catch (SoapFault $fault) 
        {
            $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
    }

    /**
     * Call Magenta API to get last updated companies
     * 
     * @param   datetime $fromDate      From date
     * @param   datetime $toDate        To date
     * @return  void|mixed              Response from SOAP call, normally an associative array mirroring the structure of the XML response, nothing if error         
     */
    public function getSocieteToUpdate($fromDate, $toDate)
    {
        try {
            dol_syslog("getSocieteToUpdate start gt = ".dol_print_date($fromDate, 'standard').", lt = ".dol_print_date($toDate, 'standard'));
            $filter = array(
                array('updated_at' => array('gt' => dol_print_date($fromDate, 'standard'), 'lt' => dol_print_date($toDate, 'standard')))
            );
            $result = $this->client->call($this->session, 'customer.list', $filter);
            dol_syslog("getSocieteToUpdate end");
            return $result;
        } catch (SoapFault $fault) {
            $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
    }

    /**
     * Call Magenta API to get last updated products. We are interested here by list of id only. We will retreive properties later.
     * 
     * @param   datetime $fromDate      From date
     * @param   datetime $toDate        To date
     * @return  void|mixed              Response from SOAP call, normally an associative array mirroring the structure of the XML response, nothing if error         
     */
    public function getProductToUpdate($fromDate, $toDate)
    {
        try {
            dol_syslog("getProductToUpdate start gt=".dol_print_date($fromDate, 'standard')." lt=".dol_print_date($toDate, 'standard'));
            $filter = array(
                array('updated_at' => array('gt' => dol_print_date($fromDate, 'standard'), 'lt' => dol_print_date($toDate, 'standard'))),
                //array('type_id', array('in' => array('simple', 'virtual', 'configurable', 'downloadable')))
            );
            $result = $this->client->call($this->session, 'catalog_product.list', $filter);
            
            $results = array();
            $productsTypesOk = array('simple', 'virtual', 'downloadable');  // We exclude configurable. TODO Get them ?
            foreach ($result as $product) 
            {
                if (in_array($product['type'], $productsTypesOk))
                {
                    $results[] = $product;
                }
            }

            dol_syslog("getProductToUpdate end");
            return $results;            
        } catch (SoapFault $fault) {
            $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
    }

    /**
     * Call Magenta API to get last updated orders
     * 
     * @param   datetime $fromDate      From date
     * @param   datetime $toDate        To date
     * @return  void|mixed              Response from SOAP call, normally an associative array mirroring the structure of the XML response, nothing if error         
     */
    public function getCommandeToUpdate($fromDate, $toDate)
    {
        try {
            dol_syslog("getCommandeToUpdate start gt=".dol_print_date($fromDate, 'standard')." lt=".dol_print_date($toDate, 'standard'));
            $filter = array(
                array('updated_at' => array('gt' => dol_print_date($fromDate, 'standard'), 'lt' => dol_print_date($toDate, 'standard'))),
            );
            $result = $this->client->call($this->session, 'sales_order.list', $filter);

            foreach ($result as $rcommande)
            {
                $calls[] = array('sales_order.info', $rcommande['increment_id']);
            }
            try {
                $results = $this->client->multiCall($this->session, $calls);
            } catch (SoapFault $fault) {
                //echo 'getCommandeToUpdate :'.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString();
            }
            
            dol_syslog("getCommandeToUpdate end");
            return $result;
        } catch (SoapFault $fault) {
            $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
    }

    /**
     * Call Magenta API to get last updated invoices
     * 
     * @param   datetime $fromDate      From date
     * @param   datetime $toDate        To date
     * @return  void|mixed              Response from SOAP call, normally an associative array mirroring the structure of the XML response, nothing if error         
     */
    public function getFactureToUpdate($fromDate, $toDate)
    {
        try {
            dol_syslog("getFactureToUpdate start gt=".dol_print_date($fromDate, 'standard')." lt=".dol_print_date($toDate, 'standard'));
            $filter = array(
                array('updated_at' => array('gt' => dol_print_date($fromDate, 'standard'), 'lt' => dol_print_date($toDate, 'standard'))),
            );
            $result = $this->client->call($this->session, 'sales_order_invoice.list', $filter);
            dol_syslog("getFactureToUpdate end");
            return $result;
        } catch (SoapFault $fault) {
            $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
    }

    
    /**
     * Put the remote data into societe dolibarr data from instantiated class in the constructor
     * Return array of thirdparty by update time.
     * 
     * @param   array   $remoteObject         Array of ids of objects to convert
     * @return  array                         societe
     */
    public function convertRemoteObjectIntoDolibarrSociete($remoteObject)
    {
        global $conf;
        
        $societes = array();
         
        $maxsizeofmulticall = (empty($conf->global->ECOMMERCENG_MAXSIZE_MULTICALL)?1000:$conf->global->ECOMMERCENG_MAXSIZE_MULTICALL);      // 1000 seems ok for multicall.
        $nbsynchro = 0;
        $nbremote = count($remoteObject);
        if ($nbremote)
        {
            // Create n groups of $maxsizeofmulticall records max to call the multiCall
            $callsgroup = array();
            $calls=array();
            foreach ($remoteObject as $rsociete)
            {
                if (($nbsynchro % $maxsizeofmulticall) == 0)
                {
                    if (count($calls)) $callsgroup[]=$calls;    // Add new group for lot of 1000 call arrays
                    $calls=array();
                }
        
                if ($rsociete['customer_id'])
                {
                    $calls[] = array('customer.info', $rsociete['customer_id']);
                }
        
                $nbsynchro++;   // nbsynchro is now number of calls to do
            }
            if (count($calls)) $callsgroup[]=$calls;    // Add new group for the remain lot of calls not yet added
        
            dol_syslog("convertRemoteObjectIntoDolibarrSociete Call WS to get detail for the ".count($remoteObject)." objects (".count($callsgroup)." calls with ".$maxsizeofmulticall." max of records each) then create a Dolibarr array for each object");
            //var_dump($callsgroup);exit;
        
            foreach ($callsgroup as $calls)
            {
                try {
                    $results = $this->client->multiCall($this->session, $calls);
                } catch (SoapFault $fault) {
                    $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
                    dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
                    dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
                    dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
                    return false;
                }
                
                if (count($results))
                {
                    foreach ($results as $societe)
                    {
                        $newobj=array(
                                'remote_id' => $societe['customer_id'],
                                'last_update' => $societe['updated_at'],
                                'name' =>dolGetFirstLastname($societe['firstname'], $societe['lastname']),
                                'name_alias' =>$this->site->name.' id '.$societe['customer_id'],                // See also the delete in eCommerceSociete
                                'email' => $societe['email'],
                                'client' => 3, //for client/prospect
                                'vatnumber'=> $societe['taxvat']
                        );
                        $societes[] = $newobj;
                    }
                }
            }
        }
        
        //important - order by last update
        if (count($societes))
        {
            foreach ($societes as $key => $row)
            {
                $last_update[$key] = $row['last_update'];
            }
            array_multisort($last_update, SORT_ASC, $societes);
        }
        return $societes;
    }

    
    /**
     * Put the remote data into societe dolibarr data from instantiated class in the constructor
     * Return array of people by update time.
     * 
     * @param   array       $listofids      List of object with customer_address_id that is remote id of addresss
     * @return  array                       societe
     */
    public function convertRemoteObjectIntoDolibarrSocpeople($listofids)
    {
        $socpeoples = array();
        $calls = array();
        if (count($listofids))
        {
            dol_syslog("convertRemoteObjectIntoDolibarrSocpeople Call WS to get detail for the ".count($listofids)." objects then create a Dolibarr array for each object");
            foreach ($listofids as $listofid)
            {
                $calls[] = array('customer_address.info', $listofid['customer_address_id']);
            }
            try {
                $results = $this->client->multiCall($this->session, $calls);
            } catch (SoapFault $fault) {
                //echo 'convertRemoteObjectIntoDolibarrSociete :'.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString();
            }
            if (count($results))
                foreach ($results as $socpeople)
                {
                    $newobj=array(
                            'remote_id' => $socpeople['customer_address_id'],
                            'last_update' => $socpeople['updated_at'],
                            'name' =>dolGetFirstLastname($socpeople['firstname'], $socpeople['lastname']),
                            'email' => $socpeople['email'],
                            'address' => $socpeople['street'],
                            'town' => $socpeople['city'],
                            'zip' => $socpeople['postcode'],
                            'country_code' => $socpeople['country_id'],
                            'phone' => $socpeople['telephone'],
                            'fax' => $socpeople['fax'],
                            'firstname' => $socpeople['firstname'],
                            'lastname' => $socpeople['lastname'],
                            'vatnumber'=> $socpeople['taxvat'],
                            'is_default_billing' => $socpeople['is_default_billing'],
                            'is_default_shipping' => $socpeople['is_default_shipping']
                    );
                    $socpeoples[] = $newobj;
                }
        }
        
        //important - order by last update
        if (count($socpeoples))
        {
            foreach ($socpeoples as $key => $row)
            {
                $last_update[$key] = $row['last_update'];
            }
            array_multisort($last_update, SORT_ASC, $socpeoples);
        }
        return $socpeoples;
    }
    
    
    /**
     * Put the remote data into product dolibarr data from instantiated class in the constructor
     * Return array or products by update time.
     * 
     * @param   array   $remoteObject   Array of remote products (got by caller from getProductToUpdate. Only few properties defined)
     * @return  array                   product
     */
    public function convertRemoteObjectIntoDolibarrProduct($remoteObject)
    {
        global $conf;
        
        include_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
        
        $products = array();
       
        $canvas = '';
        
        $maxsizeofmulticall = (empty($conf->global->ECOMMERCENG_MAXSIZE_MULTICALL)?1000:$conf->global->ECOMMERCENG_MAXSIZE_MULTICALL);      // 1000 seems ok for multicall.
        $nbsynchro = 0;
        $nbremote = count($remoteObject);
        if ($nbremote)
        {
            // Create n groups of $maxsizeofmulticall records max to call the multiCall
            $callsgroup = array();
            $calls=array();
            foreach ($remoteObject as $rproduct)
            {
                if (($nbsynchro % $maxsizeofmulticall) == 0) 
                {
                    if (count($calls)) $callsgroup[]=$calls;    // Add new group for lot of 1000 call arrays
                    $calls=array();
                }
                
                if ($rproduct['sku'])
                {
                    $calls[] = array('catalog_product.info', $rproduct['sku']);
                }
                
                $nbsynchro++;   // nbsynchro is now number of calls to do
            }
            if (count($calls)) $callsgroup[]=$calls;    // Add new group for the remain lot of calls not yet added
            
            dol_syslog("convertRemoteObjectIntoDolibarrProduct Call WS to get detail for the ".count($remoteObject)." objects (".count($callsgroup)." calls with ".$maxsizeofmulticall." max of records each) then create a Dolibarr array for each object");
            //var_dump($callsgroup);exit;

            foreach ($callsgroup as $calls)
            {
                try {
                    $results = $this->client->multiCall($this->session, $calls);
                } catch (SoapFault $fault) {
                    $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
                    dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
                    dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
                    dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
                    return false;
                }
    
                // See file example_product_array_returned_by_magento.txt 
                
                if (count($results))
                {
                    $ecommerceurl =  $this->site->getFrontUrl();
    
                    foreach ($results as $cursorproduct => $product)
                    {
                        // Complete data with info in stock
                        // Note: if product is set "do not manage stock" on magento, no information is returned and stock is returned whatever is this option.
                        try {
                            $result2 = $this->client->call($this->session, 'cataloginventory_stock_item.list', $product['product_id']);
                        } catch (SoapFault $fault) {
                            $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
                            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
                            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
                            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
                            return false;
                        }
                        //var_dump($result2);exit;
                        foreach($result2 as $val)
                        {
                            $product['stock_qty'] = $val['qty'];
                            $product['is_in_stock'] = $val['is_in_stock'];
                        }
    
                        $products[] = array(
                                //$product['type'] simple, grouped (=package), configurable (= variant), downloadable, bundle (on demand defined products), virtual (services)   
                                'fk_product_type' => ($product['type'] == 'virtual' ? 1 : 0), // 0 (product) or 1 (service)
                                'ref' => dol_sanitizeFileName(stripslashes($product['sku'])),
                                'label' => $product['name'],
                                'description' => $product['description'],
                                'weight' => $product['weight'],
                                'last_update' => $product['updated_at'],
                                'price' => (($this->site->magento_use_special_price && $product['special_price'] != NULL ) ? $product['special_price'] : $product['price']),
                                'envente' => $product['status'] ? 1 : 0,
                                'remote_id' => $product['product_id'],  // id in ecommerce magento
                                'finished' => 1,    // 1 = manufactured, 0 = raw material
                                'canvas' => $canvas,
                                'categories' => $product['categories'],     // Same as property $product['category_ids']
                                'tax_rate' => $product['tax_rate'],
                                'price_min' => $product['minimal_price'],
                                'fk_country' => ($product['country_of_manufacture'] ? getCountry($product['country_of_manufacture'], 3, $this->db, '', 0, '') : null),
                                'url' => $ecommerceurl.$product['url_path'],
                                // Stock
                                'stock_qty' => $product['stock_qty'],
                                'is_in_stock' => $product['is_in_stock'],   // not used
                        );
                        //var_dump($product['country_of_manufacture']);
                        //var_dump(getCountry($product['country_of_manufacture'], 3, $this->db, '', 0, ''));exit;
                        // We also get special_price, minimal_price => ?, msrp, 
                    }
                }
            }
        }
        //important - order by last update
        if (count($products))
        {
            foreach ($products as $key => $row)
            {
                $last_update[$key] = $row['last_update'];
            }
            array_multisort($last_update, SORT_ASC, $products);
        }
        return $products;
    }

    /**
     * Put the remote data into commande dolibarr data from instantiated class in the constructor
     * Return array of orders by update time.
     * 
     * @param   array   $remoteObject       array of remote orders
     * @return  array                       commande
     */
    public function convertRemoteObjectIntoDolibarrCommande($remoteObject)
    {
        global $conf;
        
        $commandes = array();
        
        $maxsizeofmulticall = (empty($conf->global->ECOMMERCENG_MAXSIZE_MULTICALL)?1000:$conf->global->ECOMMERCENG_MAXSIZE_MULTICALL);      // 1000 seems ok for multicall.
        $nbsynchro = 0;
        $nbremote = count($remoteObject);
        if ($nbremote)
        {
            // Create n groups of $maxsizeofmulticall records max to call the multiCall
            $callsgroup = array();
            $calls=array();
            foreach ($remoteObject as $rcommande)
            {
                if (($nbsynchro % $maxsizeofmulticall) == 0)
                {
                    if (count($calls)) $callsgroup[]=$calls;    // Add new group for lot of 1000 call arrays
                    $calls=array();
                }
        
                if ($rcommande['increment_id'])
                {
                    $calls[] = array('sales_order.info', $rcommande['increment_id']);
                }
        
                $nbsynchro++;   // nbsynchro is now number of calls to do
            }
            if (count($calls)) $callsgroup[]=$calls;    // Add new group for the remain lot of calls not yet added
        
            dol_syslog("convertRemoteObjectIntoDolibarrCommande Call WS to get detail for the ".count($remoteObject)." objects (".count($callsgroup)." calls with ".$maxsizeofmulticall." max of records each) then create a Dolibarr array for each object");
            //var_dump($callsgroup);exit;
        
            foreach ($callsgroup as $calls)
            {
                try {
                    $results = $this->client->multiCall($this->session, $calls);
                } catch (SoapFault $fault) {
                    $this->errors[]=$fault->getMessage().'-'.$fault->getCode();
                    dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
                    dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
                    dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
                    return false;
                }
                if (count($results))
                {
                    foreach ($results as $commande)
                    {
                        //set each items
                        $items = array();
                        $configurableItems = array();
                        if (count($commande['items']))
                            foreach ($commande['items'] as $item)
                            {
                                //var_dump($item);    // show item as it is from magento
                                
                                // If item is configurable, localMemCache it, to use its price and tax rate instead of the one of its child
                                if ($item['product_type'] == 'configurable') {
                                    $configurableItems[$item['item_id']] = array(
                                        'item_id' => $item['item_id'],
                                        'id_remote_product' => $item['product_id'],
                                        'description' => $item['name'],
                                        'product_type' => $item['product_type'], 
                                        'price' => $item['price'],
                                        'qty' => $item['qty_ordered'],
                                        'tva_tx' => $item['tax_percent']
                                    );
                                } else {
                                    // If item has a parent item id defined in $configurableItems, it's a child simple item so we get it's price and tax values instead of 0
                                    if (!array_key_exists($item['parent_item_id'], $configurableItems)) {
                                        $items[] = array(
                                                'item_id' => $item['item_id'],
                                                'id_remote_product' => $item['product_id'],
                                                'description' => $item['name'],
                                                'product_type' => $item['product_type'], 
                                                'price' => $item['price'],
                                                'qty' => $item['qty_ordered'],
                                                'tva_tx' => $item['tax_percent']
                                        );
                                    } else {
                                        $items[] = array(
                                                'item_id' => $item['item_id'],
                                                'id_remote_product' => $item['product_id'],
                                                'description' => $item['name'],
                                                'product_type' => $item['product_type'], 
                                                'price' => $configurableItems[$item['parent_item_id']]['price'],
                                                'qty' => $item['qty_ordered'],
                                                'tva_tx' => $configurableItems[$item['parent_item_id']]['tva_tx']
                                        );
                                    }
                                }
                            }
    
                        //set order's address
                        $commandeSocpeople = $commande['billing_address'];
                        $socpeopleCommande = array(
                                'remote_id' => $commandeSocpeople['address_id'],
                                'type' => eCommerceSocpeople::CONTACT_TYPE_ORDER,
                                'last_update' => $commandeSocpeople['updated_at'],
                                'name' => $commandeSocpeople['lastname'],
                                'lastname' => $commandeSocpeople['lastname'],
                                'firstname' => $commandeSocpeople['firstname'],
                                'town' => $commandeSocpeople['city'],
                                //'fk_pays' => $commandeSocpeople['country_id'],
                                'fax' => $commandeSocpeople['fax'],
                                'zip' => $commandeSocpeople['postcode'],
                                //add wrap
                                'address' => addslashes((trim($commandeSocpeople['company'])) != '' ? addslashes(trim($commandeSocpeople['company'])) . ', ' : '') . addslashes($commandeSocpeople['street']),
                                'phone' => $commandeSocpeople['telephone']
                        );
                        
                        //set billing's address
                        $socpeopleFacture = $socpeopleCommande;
                        $socpeopleFacture['type'] = eCommerceSocpeople::CONTACT_TYPE_INVOICE;
    
                        //set shipping's address
                        $livraisonSocpeople = $commande['shipping_address'];
                        $socpeopleLivraison = array(
                                'remote_id' => $livraisonSocpeople['address_id'],
                                'type' => eCommerceSocpeople::CONTACT_TYPE_DELIVERY,
                                'last_update' => $livraisonSocpeople['updated_at'],
                                'name' => $livraisonSocpeople['lastname'],
                                'lastname' => $livraisonSocpeople['lastname'],
                                'firstname' => $livraisonSocpeople['firstname'],
                                'town' => $livraisonSocpeople['city'],
                                //'fk_pays' => $commandeSocpeople['country_id'],
                                'fax' => $livraisonSocpeople['fax'],
                                'zip' => $livraisonSocpeople['postcode'],
                                //add wrap
                                'address' => (trim($livraisonSocpeople['company']) != '' ? trim($livraisonSocpeople['company']) . ', ' : '') . $livraisonSocpeople['street'],
                                'phone' => $livraisonSocpeople['telephone']
                        );
    
                        //set delivery as service
                        $delivery = array(
                                'description' => $commande['shipping_description'],
                                'price' => $commande['shipping_amount'],
                                'qty' => 1, //0 to not show
                                'tva_tx' => $this->getTaxRate($commande['shipping_amount'], $commande['shipping_tax_amount'])
                        );
    
                        //define remote id societe : 0 for anonymous
                        $eCommerceTempSoc = new eCommerceSociete($this->db);
                        if ($commande['customer_id'] == null || $eCommerceTempSoc->fetchByRemoteId($commande['customer_id'], $this->site->id) < 0)
                        {
                            dol_syslog("The customer of this order was not found into table link", LOG_WARNING);
                            $remoteIdSociete = 0;   // If thirdparty was not found into thirdparty table link
                        }
                        else
                        {
                            $remoteIdSociete = $commande['customer_id'];
                        }
    
                        //define delivery date
                        if (isset($commande['delivery_date']) && $commande['delivery_date'] != null)
                            $deliveryDate = $commande['delivery_date'];
                        else
                            $deliveryDate = $commande['created_at'];
    
                        // define status of order
                        // $commande['state'] is: 'pending', 'processing', 'closed', 'complete', 'canceled'
                        // $commande['status'] is more accurate: 'pending_...', 'canceled_...'
                        $tmp = $commande['status'];
                        
                        // try to match dolibarr status
                        $status = '';
                        if (preg_match('/^pending/', $tmp))         $status = Commande::STATUS_VALIDATED;           // manage 'pending', 'pending_payment', 'pending_paypal', 'pending_ogone', 'pending_...'
                        elseif ($tmp == 'fraud')                    $status = Commande::STATUS_VALIDATED;
                        elseif ($tmp == 'payment_review')           $status = Commande::STATUS_VALIDATED;
                        elseif ($tmp == 'paypal_canceled_reversal') $status = Commande::STATUS_VALIDATED;
                        elseif ($tmp == 'processing')               $status = 2;                                     // shipment in process or invoice done = processing       // Should be constant Commande::STATUS_SHIPMENTONPROCESS but not defined in dolibarr 3.9
                        elseif ($tmp == 'holded')                   $status = Commande::STATUS_CANCELED;
                        elseif (preg_match('/^canceled/', $tmp))    $status = Commande::STATUS_CANCELED;             // manage 'canceled', 'canceled_bnpmercanetcw', 'canceled_...'
                        elseif ($tmp == 'paypal_reversed')          $status = Commande::STATUS_CANCELED;
                        elseif ($tmp == 'complete')                 $status = Commande::STATUS_CLOSED;
                        elseif ($tmp == 'closed')                   $status = Commande::STATUS_CLOSED;
                        if ($status == '') 
                        {
                            dol_syslog("Status: We found an order id ".$commande['increment_id']." with ecommerce status '".$tmp."' that is unknown, not supported. We will use '0' for Dolibarr", LOG_WARNING);
                            $status = Commande::STATUS_DRAFT;   // draft by default (draft does not exists with magento, so next line will set correct status)    
                        }
                        else
                        {
                            dol_syslog("Status: We found an order id ".$commande['increment_id']." with ecommerce status '".$tmp."'. We convert it into Dolibarr status '".$status."'");
                        }
                            
                        // try to match dolibarr billed status (payed or not)
                        $billed = -1;   // unknown
                        if ($commande['state'] == 'pending') $billed = 0;
                        if ($commande['state'] == 'payment_review') $billed = 0;    // Error in payment
                        if ($commande['state'] == 'complete') $billed = 1;          // We are sure for complete that order is payed
                        if ($commande['state'] == 'closed') $billed = 1;            // We are sure for closed that order was payed but refund
                        if ($commande['state'] == 'canceled') $billed = 0;          // We are sure for canceled that order was not payed
                        // Note: with processing, billed can be 0 or 1, so we keep -1
                        
                        
                        // Add order content to array or orders
                        $commandes[] = array(
                                'last_update' => $commande['updated_at'],
                                'remote_id' => $commande['order_id'],
                                'remote_increment_id' => $commande['increment_id'],
                                'remote_id_societe' => $remoteIdSociete,
                                'ref_client' => $commande['increment_id'],
                                'date_commande' => $commande['created_at'],
                                'date_livraison' => $deliveryDate,
                                'items' => $items,
                                'delivery' => $delivery,
                                'socpeopleCommande' => $socpeopleCommande,
                                'socpeopleFacture' => $socpeopleFacture,
                                'socpeopleLivraison' => $socpeopleLivraison,
                                'status' => $status,                         // dolibarr status
                                'billed'=> $billed,
                                'remote_state' => $commande['state'],        // remote state, for information only (less accurate than status)
                                'remote_status' => $commande['status'],      // remote status, for information only (more accurate than state)
                                'remote_order' => $commande
                        );
                    }
                }
            }
        }
        
        //important - order by last update
        if (count($commandes))
        {
            foreach ($commandes as $key => $row)
            {
                $last_update[$key] = $row['last_update'];
            }
            array_multisort($last_update, SORT_ASC, $commandes);
        }
        
        dol_syslog("convertRemoteObjectIntoDolibarrCommande Return ".count($commandes)." array of orders filled with complete data from eCommerce");
        return $commandes;
    }

    /**
     * Put the remote data into facture dolibarr data from instantiated class
     * Return array of invoices by update time.
     * 
     * @param   array   $remoteObject       array of remote invoices
     * @return  array                       facture
     */
    public function convertRemoteObjectIntoDolibarrFacture($remoteObject)
    {
        global $conf;
        
        $factures = array();
                 
        $maxsizeofmulticall = (empty($conf->global->ECOMMERCENG_MAXSIZE_MULTICALL)?1000:$conf->global->ECOMMERCENG_MAXSIZE_MULTICALL);      // 1000 seems ok for multicall.
        $nbsynchro = 0;
        $nbremote = count($remoteObject);
        if ($nbremote)
        {
            // Create n groups of $maxsizeofmulticall records max to call the multiCall
            $callsgroup = array();
            $calls=array();
            foreach ($remoteObject as $rfacture)
            {
                if (($nbsynchro % $maxsizeofmulticall) == 0)
                {
                    if (count($calls)) $callsgroup[]=$calls;    // Add new group for lot of 1000 call arrays
                    $calls=array();
                }
        
                if ($rfacture['increment_id'])
                {
                    $calls[] = array('sales_order_invoice.info', $rfacture['increment_id']);
                }
        
                $nbsynchro++;   // nbsynchro is now number of calls to do
            }
            if (count($calls)) $callsgroup[]=$calls;    // Add new group for the remain lot of calls not yet added
        
            dol_syslog("convertRemoteObjectIntoDolibarrSociete Call WS to get detail for the ".count($remoteObject)." objects (".count($callsgroup)." calls with ".$maxsizeofmulticall." max of records each) then create a Dolibarr array for each object");
            //var_dump($callsgroup);exit;
        
            foreach ($callsgroup as $calls)
            {
                try {
                    $results = $this->client->multiCall($this->session, $calls);
                } catch (SoapFault $fault) {
                    //echo 'convertRemoteObjectIntoDolibarrFacture :'.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString();
                }
                if (count($results))
                {
                    $i=0;
                    foreach ($results as $facture)
                    {
                        $i++;
                        
                        $configurableItems = array();
                        //retrive remote order from invoice
                        $commande = $this->getRemoteCommande($facture['order_id']);
                        //set each invoice items
                        $items = array();
                        if (count($facture['items']))
                            foreach ($facture['items'] as $item)
                            {
                                //var_dump($item);    // show invoice item as it is from magento
                                
                                $product_type = $this->getProductTypeOfItem($item, $commande, $facture);
                                $parent_item_id = $this->getParentItemOfItem($item, $commande, $facture);
                                
                                // If item is configurable, localMemCache it, to use its price and tax rate instead of the one of its child
                                if ($product_type == 'configurable') {
                                    $configurableItems[$item['item_id']] = array(
                                        'item_id' => $item['item_id'],
                                        'id_remote_product' => $item['product_id'],
                                        'description' => $item['name'],
                                        'product_type' => $product_type, 
                                        'price' => $item['price'],
                                        'qty' => $item['qty'],
                                        'tva_tx' => $this->getTaxRate($item['row_total'], $item['tax_amount'])
                                    );
                                } else {
                                    // If item has a parent item id defined in $configurableItems, it's a child simple item so we get it's price and tax values instead of 0
                                    if (!array_key_exists($parent_item_id, $configurableItems)) {
                                        $items[] = array(
                                                'item_id' => $item['item_id'],
                                                'id_remote_product' => $item['product_id'],
                                                'description' => $item['name'],
                                                'product_type' => $product_type, 
                                                'price' => $item['price'],
                                                'qty' => $item['qty'],
                                                'tva_tx' => $this->getTaxRate($item['row_total'], $item['tax_amount'])
                                        );
                                    } else {
                                        $items[] = array(
                                                'item_id' => $item['item_id'],
                                                'id_remote_product' => $item['product_id'],
                                                'description' => $item['name'],
                                                'product_type' => $product_type, 
                                                'price' => $configurableItems[$parent_item_id]['price'],
                                                'qty' => $item['qty'],
                                                'tva_tx' => $configurableItems[$parent_item_id]['tva_tx']
                                        );
                                    }
                                }
                            }
                            
                        //set shipping address
                        $shippingAddress = $commande["shipping_address"];
                        $billingAddress = $commande["billing_address"];
                        $socpeopleLivraison = array(
                                'remote_id' => $shippingAddress['address_id'],
                                'type' => eCommerceSocpeople::CONTACT_TYPE_DELIVERY,
                                'last_update' => $shippingAddress['updated_at'],
                                'name' => $shippingAddress['lastname'],
                                'firstname' => $shippingAddress['firstname'],
                                'ville' => $shippingAddress['city'],
                                //'fk_pays' => $commandeSocpeople['country_id'],
                                'fax' => $shippingAddress['fax'],
                                'cp' => $shippingAddress['postcode'],
                                //add wrap
                                'address' => (trim($shippingAddress['company']) != '' ? trim($shippingAddress['company']) . '
                                                                                ' : '') . $shippingAddress['street'],
                                'phone' => $shippingAddress['telephone']
                        );
                        //set invoice address		
                        $socpeopleFacture = array(
                                'remote_id' => $billingAddress['address_id'],
                                'type' => eCommerceSocpeople::CONTACT_TYPE_INVOICE,
                                'last_update' => $billingAddress['updated_at'],
                                'name' => $billingAddress['lastname'],
                                'firstname' => $billingAddress['firstname'],
                                'ville' => $billingAddress['city'],
                                //'fk_pays' => $commandeSocpeople['country_id'],
                                'fax' => $billingAddress['fax'],
                                'cp' => $billingAddress['postcode'],
                                //add wrap
                                'address' => (trim($billingAddress['company']) != '' ? trim($billingAddress['company']) . '
                                                                                ' : '') . $billingAddress['street'],
                                'phone' => $billingAddress['telephone']
                        );
                        //set delivery as service			
                        $delivery = array(
                                'description' => $commande['shipping_description'],
                                'price' => $facture['shipping_amount'],
                                'qty' => 1, //0 to not show
                                'tva_tx' => $this->getTaxRate($facture['shipping_amount'], $facture['shipping_tax_amount'])
                        );
    
                        $eCommerceTempSoc = new eCommerceSociete($this->db);
                        if ($commande['customer_id'] == null || $eCommerceTempSoc->fetchByRemoteId($commande['customer_id'], $this->site->id) < 0)
                        {
                            $remoteIdSociete = 0;
                        }
                        else
                        {
                            $remoteIdSociete = $commande['customer_id'];
                        }
                        
                        // load local order to be used to retreive some data for invoice
                        $eCommerceTempCommande = new eCommerceCommande($this->db);
                        $eCommerceTempCommande->fetchByRemoteId($commande['order_id'], $this->site->id);
                        $dbCommande = new Commande($this->db);
                        $dbCommande->fetch($eCommerceTempCommande->fk_commande);
    
                        
                        // define status of invoice
                        $tmp = $facture['state'];                                                   // state from is 1, 2, 3
                        
                        // try to match dolibarr status
                        $status = '';
                        if ($tmp == 1)     $status = Facture::STATUS_VALIDATED;            // validated = pending
                        if ($tmp == 2)     $status = Facture::STATUS_CLOSED;               // complete
                        if ($tmp == 3)     $status = Facture::STATUS_ABANDONED;            // canceled = holded
                        if ($status == '')
                        {
                            dol_syslog("Status: We found an invoice id ".$commande['increment_id']." with ecommerce status '".$tmp."' that is unknown, not supported. We will use '0' for Dolibarr", LOG_WARNING);
                            $status = Facture::STATUS_DRAFT;                                            // draft by default (draft does not exists with magento, so next line will set correct status)
                        }
                        else
                        {
                            dol_syslog("Status: We found an invoice id ".$commande['increment_id']." with ecommerce status '".$tmp."'. We convert it into Dolibarr status '".$status."'");
                        }
                        
                        
                        $close_code = '';
                        $close_note = '';
                        if ($tmp == 3)     
                        {
                            $close_code = Facture::CLOSECODE_ABANDONED;
                            $close_note = 'Holded on ECommerce';
                        }
    
                        //add invoice to invoices
                        $factures[] = array(
                                'last_update' => $facture['updated_at'],
                                'remote_id' => $facture['invoice_id'],
                                'remote_increment_id' => $facture['increment_id'],
                                'ref_client' => $facture['increment_id'],
                                'remote_order_id' => $facture['order_id'],
                                'remote_order_increment_id' => $facture['order_increment_id'],
                                'remote_id_societe' => $remoteIdSociete,
                                'socpeopleLivraison' => $socpeopleLivraison,
                                'socpeopleFacture' => $socpeopleFacture,
                                'date' => $facture['created_at'],
                                'code_cond_reglement' => $dbCommande->cond_reglement_code,      // Take for local order
                                'delivery' => $delivery,
                                'items' => $items,
                                'status' => $tmp,
                                'close_code' => $close_code,
                                'close_note' => $close_note,
                                'remote_state' => $facture['state'],
                                'remote_order' => $commande,
                                'remote_invoice' => $facture
                        );
                    }
                }
            }
        }
        
        //important - order by last update
        if (count($factures))
        {
            foreach ($factures as $key => $row)
            {
                $last_update[$key] = $row['last_update'];
            }
            array_multisort($last_update, SORT_ASC, $factures);
        }
        
        //var_dump($factures);exit;
        
        return $factures;
    }

    
    /**
     * Return if type of an item of invoice (information comue from item of order)
     * 
     * @param   array   $item       Item of invoice
     * @param   array   $commande   Commande with items
     * @param   array   $facture    Facture with items
     * @return string
     */
    function getProductTypeOfItem($item, $commande, $facture)
    {
        $product_type = 'notfound';   // By default
        
        //print "Try to find product type of invoice item_id=".$item['item_id']." (invoice ".$facture['increment_id'].") and order_item_id=".$item['order_item_id']." (order ".$commande['increment_id'].")\n";
        
        $order_item_id = $item['order_item_id'];
        
        // We scan item of order to find this order item id
        foreach($commande['items'] as $itemorder)
        {
            if ($itemorder['item_id'] == $order_item_id)
            {
                // We've got it
                $product_type = $itemorder['product_type'];
                break;
            }
        }

        //print "Found product type = ".$product_type."\n";
        
        if ($product_type == 'notfound') $product_type = 'simple';
        
        return $product_type;
    }
    
    /**
     * Return if type of an item of invoice (information comue from item of order)
     *
     * @param   array   $item       Item of invoice
     * @param   array   $commande   Commande with items
     * @param   array   $facture    Facture with items
     * @return string
     */
    function getParentItemOfItem($item, $commande, $facture)
    {
        //print "Try to find invoice parent item id of invoice item_id=".$item['item_id']." (invoice ".$facture['increment_id'].") and order_item_id=".$item['order_item_id']." (order ".$commande['increment_id'].")\n";
        
        $parent_item_id = 0;   // By default
        $parent_item_id_in_order = 0;
        
        $order_item_id = $item['order_item_id'];
    
        // We scan item of order to find this order item id
        foreach($commande['items'] as $itemorder)
        {
            if ($itemorder['item_id'] == $order_item_id)
            {
                // We've got it
                $product_type = $itemorder['product_type'];
                
                $parent_item_id_in_order = $itemorder['parent_item_id'];
                break;
            }
        }
    
        // If the item is linked to an order item id that has a parent order item id
        if ($parent_item_id_in_order)
        {
            // We scan now invoice items to find the item that is linked to order item id $parent_item_id_in_order
            foreach($facture['items'] as $itemfacture)
            {
                if ($itemfacture['order_item_id'] == $parent_item_id_in_order)
                {
                    // We've got it
                    $parent_item_id = $itemfacture['item_id'];
                    break;
                }
            }
        }        
        
        //print "Found invoice parent_item_id=".$parent_item_id." and order parent_item_id=".$parent_item_id_in_order."\n";
        
        return $parent_item_id;
    }
    
    
    
    
    // Now functions to get data on remote shop, from the remote id.
    
    
    /**
     * Return the magento's category tree
     * 
     * @return  array|boolean       Array with categories or false if error
     */
    public function getRemoteCategoryTree()
    {
        dol_syslog("eCommerceRemoteAccessMagento getRemoteCategoryTree session=".$this->session);
        try {
            //$result = $this->client->call($this->session, 'auguria_dolibarrapi_catalog_category.tree');
            $result = $this->client->call($this->session, 'catalog_category.tree');
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog($this->client->__getLastResponseHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastResponse(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        //var_dump($result);
        dol_syslog("eCommerceRemoteAccessMagento getRemoteCategoryTree end. Nb of record of result = ".count($result));
        return $result;
    }
    
    /**
     * Return the magento's category att
     *
     * @return  array|boolean       Array with categories or false if error
     */
    /*public function getRemoteCategoryAtt()
    {
        dol_syslog("eCommerceRemoteAccessMagento getRemoteCategoryAtt session=".$this->session);
        try {
            //$result = $this->client->call($this->session, 'auguria_dolibarrapi_catalog_category.tree');
            $result = $this->client->call($this->session, 'catalog_category_attribute.list');
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento getRemoteCategoryAtt end");
        return $result;
    }*/
    
    /**
     * Return the magento's address id
     * 
     * @param   int             $remote_thirdparty_id       Id of thirdparty
     * @return  array|boolean                               Array with address id
     */
    public function getRemoteAddressIdForSociete($remote_thirdparty_id)
    {
        dol_syslog("eCommerceRemoteAccessMagento getRemoteAddressIdForSociete session=".$this->session);
        try {
            //$result = $this->client->call($this->session, 'auguria_dolibarrapi_catalog_category.tree');
            $result = $this->client->call($this->session, 'customer_address.list', array('customerId'=>$remote_thirdparty_id));
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento getRemoteAddressIdForSociete end");
        return $result;
    }

    
    /**
     * Return content of one category
     * 
     * @param   int             $category_id        Remote category id
     * @return  boolean|unknown                     Return
     */
    public function getCategoryData($category_id)
    {
        dol_syslog("eCommerceRemoteAccessMagento getCategoryData session=".$this->session);
        try {
            //$result = $this->client->call($this->session, 'auguria_dolibarrapi_catalog_category.tree');
            $result = $this->client->call($this->session, 'catalog_category.info', array('categoryId'=>$category_id));
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento getCategoryData end");
        return $result;
    }

    /**
     * Return the magento's order
     *
     * @param   int         $remoteCommandeId       Id of remote order
     * @return  object                              Order
     */
    public function getRemoteCommande($remoteCommandeId)
    {
        $commande = array();
        try {
            dol_syslog("getCommande begin");
            $result = $this->client->call($this->session, 'sales_order.list', array(array('order_id' => $remoteCommandeId)));
            //dol_syslog($this->client->__getLastRequest());
            if (count($result == 1))
            {
                $commande = $this->client->call($this->session, 'sales_order.info', $result[0]['increment_id']);
                //dol_syslog($this->client->__getLastRequest());
            }
            dol_syslog("getCommande end");
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        return $commande;
    }
    
    
    
    /**
     * Update the remote product
     * 
     * @param   int     $remote_id      Id of product on remote ecommerce
	 * @param   Product $object         Product object
     * @return  boolean                 True or false
     */
    public function updateRemoteProduct($remote_id, $object)
    {
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteProduct session=".$this->session." remote_id=".$remote_id." object->id=".$object->id);

        $result = false;
        
        $new_country_code = getCountry($object->country_id, 2);

        try {        
			$productData = array(
			    'sku' => $object->ref,
			    'name' => $object->label,
			    'description' => $object->description,
			    //'short_description' => 'Product short description',
			    'weight' => $object->weight,
			    'status' => $object->status,
			    'country_of_manufacture' => $object->country_code,
			    //'url_key' => 'product-url-key',
			    //'url_path' => 'product-url-path',
			    //'visibility' => '4',
			    //'tax_class_id' => 1,
			    //'meta_title' => 'Product meta title',
			    //'meta_keyword' => 'Product meta keyword',
			    //'meta_description' => 'Product meta description'
			);
			if ($new_country_code) $productData['country_of_manufacture']=$new_country_code;
			if ($this->site->magento_use_special_price) $productData['special_price']=$object->price;
			else $productData['price']=$object->price;
        	
        	$result = $this->client->call($this->session, 'catalog_product.update', array($remote_id, $productData, null, 'product_id'));
        	//dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteProduct end");
        return $result;
    }

    /**
     * Update the remote stock of product
     *
     * @param   int         $remote_id      Id of product on remote ecommerce
     * @param   Movement    $object         Movement object, enhanced with property qty_after be the trigger STOCK_MOVEMENT.
     * @return  boolean                     True or false
     */
    public function updateRemoteStockProduct($remote_id, $object)
    {
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteStockProduct session=".$this->session." product remote_id=".$remote_id." movement object->id=".$object->id.", new qty=".$object->qty_after);
    
        $result = false;
        
        // $object->qty is the qty of movement
        try {
            $stockItemData = array(
                'qty' => $object->qty_after,
                //'is_in_stock ' => 1,
                //'manage_stock ' => 1,
                //'use_config_manage_stock' => 0,
                //'min_qty' => 2,
                //'use_config_min_qty ' => 0,
                //'min_sale_qty' => 1,
                //'use_config_min_sale_qty' => 0,
                //'max_sale_qty' => 10,
                //'use_config_max_sale_qty' => 0,
                //'is_qty_decimal' => 0,
                //'backorders' => 1,
                //'use_config_backorders' => 0,
                //'notify_stock_qty' => 10,
                //'use_config_notify_stock_qty' => 0
            );
             
            $result = $this->client->call($this->session, 'cataloginventory_stock_item.update', array($remote_id, $stockItemData));
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteStockProduct end");
        return $result;
    }    
    
    /**
     * Update the remote societe
     *
     * @param   int     $remote_id      Id of societe on remote ecommerce
     * @param   Societe $object         Societe object
     * @return  boolean                 True or false
     */
    public function updateRemoteSociete($remote_id, $object)
    {
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteSociete session=".$this->session." remote_id=".$remote_id." object->id=".$object->id);
    
        $result = false;
        
        //$new_country_code = getCountry($object->country_id, 2);
    
        try {
            $societeData = array(
                //'name' => $object->name,
                //'firstname' => $object->firstname,
                //'lastname' => $object->lastname,
                'email' => $object->email
            );
            /*if ($new_country_code) $productData['country_of_manufacture']=$new_country_code;
            if ($this->site->magento_use_special_price) $productData['special_price']=$object->price;
            else $productData['price']=$object->price;*/
            
            $result = $this->client->call($this->session, 'customer.update', array($remote_id, $societeData));
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteSociete end");
        return $result;
    }
    
    /**
     * Update the remote contact
     *
     * @param   int     $remote_id      Id of contact on remote ecommerce
     * @param   Contact $object         Contact object
     * @return  boolean                 True or false
     */
    public function updateRemoteSocpeople($remote_id, $object)
    {
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteSocpeople session=".$this->session." remote_id=".$remote_id." object->id=".$object->id);
    
        $result = false;
        
        $new_country_code = getCountry($object->country_id, 2);

        try {
            $contactData = array(
                //'name' => $object->name,
                'firstname' => $object->firstname,
                'lastname' => $object->lastname,
                'street' => array($object->address, ''),
                'city' => $object->town,
                'postcode' => $object->zip,
                //'email' => $object->email
                'telephone' => $object->phone_pro,
                'fax' => $object->fax,
                //'is_default_billing'
                //'is_default_shipping'
            );
            if ($new_country_code) $contactData['country_id']=$new_country_code;
             
            $result = $this->client->call($this->session, 'customer_address.update', array($remote_id, $contactData));
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteSocpeople end");
        return $result;
    }
    
    /**
     * Update the remote order
     * 
     * @param   int      $remote_id     Id of order on remote ecommerce
	 * @param   Commande $object        Commande object
     * @return  boolean                 True or false
     */
    public function updateRemoteCommande($remote_id, $object)
    {
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteCommande session=".$this->session." remote_id=".$remote_id." object->id=".$object->id);

        $result = true;
        
        try {
            $message='';
            if ($object->oldcopy->statut != $object->statut)    // If status has changed
            {
                //if ($object->statut == Commande::STATUS_VALIDATED)  $message='sale_order.pending';      // ??? does not exists
                if ($object->statut == 2) $message='sale_order.unhold'; // ???
                if ($object->statut == Commande::STATUS_CANCELED) $message='sale_order.cancel';        // Canceled 
                //if ($object->statut == Commande::STATUS_CLOSED) $message='sale_order.closed'; // ???
            }
            if ($message)
            {
            	$result = $this->client->call($this->session, $message, array($remote_id));
            	//dol_syslog($this->client->__getLastRequest());
            }
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteCommande end");
        return $result;
    }

    /**
     * Update the remote invoice
     *
     * @param   int      $remote_id     Id of invoice on remote ecommerce
     * @param   Facture $object         Invoice object
     * @return  boolean                 True or false
     */
    public function updateRemoteFacture($remote_id, $object)
    {
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteFacture session=".$this->session." remote_id=".$remote_id." object->id=".$object->id);
    
        $result = false;
        /*
        try {
            $factureData = array(
                'status' => $object->status,
            );
             
            $result = $this->client->call($this->session, 'invoice.update', array($remote_id, $factureData, null, 'order_id'));
            //dol_syslog($this->client->__getLastRequest());
        } catch (SoapFault $fault) {
            $this->errors[]=$this->site->name.': '.$fault->getMessage().'-'.$fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__.': '.$fault->getMessage().'-'.$fault->getCode().'-'.$fault->getTraceAsString(), LOG_WARNING);
            return false;
        }*/
        dol_syslog("eCommerceRemoteAccessMagento updateRemoteFacture end");
        return $result;
    }    
    
    /**
     * Create shipment
     * 
     * @param   int     $livraison              Object shipment ?
     * @param   int     $remote_order_id        Id of order
     * @return  boolean                         True or false
     */
    public function createRemoteLivraison($livraison, $remote_order_id)
    {
        $result = false;

        dol_syslog("eCommerceRemoteAccessMagento createRemoteLivraison session=" . $this->session . " dolibarr shipment id = " . $livraison->id . ", ref = " . $livraison->ref . ", order remote id = " . $remote_order_id);
        $remoteCommande = $this->getRemoteCommande($remote_order_id); // SOAP request to get data
        $livraisonArray = get_object_vars($livraison);
        try {
            $orderItemQty = array();
            foreach ($remoteCommande['items'] as $productMagento) {
                foreach ($livraisonArray['lines'] as $lines) {
                    if ($lines->product_ref == $productMagento['sku']) {
                        $orderItemQty[$productMagento['item_id']] = $lines->qty_shipped;
                    }
                }
            }
            $result = $this->client->call($this->session, 'sales_order_shipment.create', array(
                $remoteCommande['increment_id'],
                $orderItemQty,
                'Shipment Created from ' . ($livraison->newref ? $livraison->newref : $livraison->ref),
                true,
                true
            ));
            //dol_syslog($this->client->__getLastResponse());
        } catch (SoapFault $fault) {
            $this->errors[] = $this->site->name . ': ' . $fault->getMessage() . ' - ' . $fault->getCode();
            dol_syslog($this->client->__getLastRequestHeaders(), LOG_WARNING);
            dol_syslog($this->client->__getLastRequest(), LOG_WARNING);
            dol_syslog(__METHOD__ . ': ' . $fault->getMessage() . '-' . $fault->getCode() . '-' . $fault->getTraceAsString(), LOG_WARNING);
            dol_syslog($this->client->__getLastResponse(),LOG_WARNING);
            return false;
        }
        dol_syslog("eCommerceRemoteAccessMagento createRemoteLivraison end");
        return $result;
    }
    
    
    
    
    /**
     * Calcul tax rate and return the closest dolibarr tax rate.
     * 
     * @param float $priceHT         Price HT
     * @param float $taxAmount       Tax amount
     */
    private function getTaxRate($priceHT, $taxAmount)
    {
        $taxRate = 0;
        if ($taxAmount != 0)
        {
            //calcul tax rate from remote site
            $tempTaxRate = ($taxAmount / $priceHT) * 100;
            //get all dolibarr tax rates
            if (!isset($this->taxRates))
                $this->setTaxRates();
            if (count($this->taxRates))
            {
                $min = 1;
                $rate;
                foreach ($this->taxRates as $dolibarrTaxRate)
                {
                    $diff = $tempTaxRate - $dolibarrTaxRate['taux'];
                    if ($diff < 0)
                        $diff = (-1 * $diff);
                    if ($diff < $min)
                    {
                        $min = $diff;
                        $rate = $dolibarrTaxRate['taux'];
                    }
                }
                if ($rate > 0)
                    $taxRate = $rate;
            }
        }
        return $taxRate;
    }

    /**
     * Retrieve all Dolibarr tax rates
     */
    private function setTaxRates()
    {
        $taxTable = new eCommerceDict($this->db, MAIN_DB_PREFIX . "c_tva");
        $this->taxRates = $taxTable->getAll();
    }

    public function __destruct()
    {
        if (is_object($this->client)) $this->client->endSession($this->session);
        ini_set("memory_limit", "528M");
    }

}

