<?php

/**
 * Central class for connecting to EIT resources used by VuFind.
 *
 * PHP version 5
 *
 * Copyright (C) Julia Bauder 2013.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Connection
 * @author   Julia Bauder <bauderj@grinnell.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/system_classes Wiki
 */
namespace VuFindSearch\Backend\EIT;

use VuFindSearch\ParamBag;
use VuFindSearch\Backend\Exception\HttpErrorException;

use Zend\Http\Request;

use Zend\Log\LoggerInterface;
/**
 * Central class for connecting to EIT resources used by VuFind.
 *
 * @category VuFind2
 * @package  Connection
 * @author   Julia Bauder <bauderj@grinnell.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/system_classes Wiki
 */
class Connector
{
    /**
     * Base url for searches
     *
     * @var string
     */
    protected $base;

    /**
     * The HTTP_Request object used for REST transactions
     *
     * @var \Zend\Http\Client
     */
    protected $client;

    /**
     * EBSCO EIT Profile used for authentication
     *
     * @var string
     */
    protected $prof;

    /**
     * Password associated with the EBSCO EIT Profile
     *
     * @var string
     */
    protected $pwd;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    protected $logger = false;

    /**
     * Array of 3-character EBSCO database abbreviations to include in search
     *
     * @var array
     */
    protected $dbs = array();

    /**
     * Constructor
     *
     * @param string            $base   Base URL
     * @param \Zend\Http\Client $client HTTP client
     * @param string            $prof   Profile
     * @param string            $pwd    Password
     * @param string            $dbs    Database list (comma-separated abbrevs.)
     */
    public function __construct($base, \Zend\Http\Client $client, $prof, $pwd, $dbs)
    {
        $this->base = $base;
        $this->client = $client;
        $this->prof = $prof;
        $this->pwd = $pwd;
        $this->dbs = $dbs;
    }

    /**
     * Set logger instance.
     *
     * @param LoggerInterface $logger Logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Log a debug message.
     *
     * @param string $msg Message to log.
     *
     * @return void
     */
    protected function debug($msg)
    {
        if ($this->logger) {
            $this->logger->debug($msg);
        }
    }

    /**
     * Execute a search.
     *
     * @param ParamBag $params Parameters
     * @param integer  $offset Search offset
     * @param integer  $limit  Search limit
     *
     * @return array
     */
    public function search(ParamBag $params, $offset, $limit)
    {
        $startrec = $offset + 1;
        $params->set('startrec', $startrec);
        $params->set('numrec', $limit);
        $params->set('prof', $this->prof);
        $params->set('pwd', $this->pwd);
        $response = $this->call('GET', $params->getArrayCopy(), false);
        $xml = simplexml_load_string($response);
        $finalDocs = array();
        foreach ($xml->SearchResults->records->rec as $doc) {
            $finalDocs[] = simplexml_load_string($doc->asXML());
        }
        return array(
            'docs' => $finalDocs,
            'offset' => $offset,
            'total' => (integer)$xml->Hits
        );
    }

    /**
     * Check for HTTP errors in a response.
     *
     * @param \Zend\Http\Response $result The response to check.
     *
     * @throws BackendException
     * @return void
     */
    public function checkForHttpError($result)
    {
        if (!$result->isSuccess()) {
            throw HttpErrorException::createFromResponse($result);
        }
    }

    /**
     * Make an API call
     *
     * @param string $method GET or POST
     * @param array  $params Parameters to send
     *
     * @return \SimpleXMLElement
     */
    protected function call($method = 'GET', $params = null)
    {
        if ($params) {
            $query = array();
            foreach ($params as $function => $value) {
                if (is_array($value)) {
                    foreach ($value as $additional) {
                        $additional = urlencode($additional);
                        $query[] = "$function=$additional";
                    }
                } else {
                    $value = urlencode($value);
                    $query[] = "$function=$value";
                }
            }
            $queryString = implode('&', $query);
        }

        $dbs = explode(',', $this->dbs);
        $dblist = '';
        foreach ($dbs as $db) {
            $dblist .= "&db=" . $db;
        }

        $this->debug(
            'Connect: ' . print_r($this->base . '?' . $queryString . $dblist, true)
        );

        // Send Request
        $this->client->resetParameters();
        $this->client->setUri($this->base . '?' . $queryString . $dblist);
        $result = $this->client->setMethod($method)->send();
        $body = $result->getBody();
        $xml = simplexml_load_string($body);
        $this->debug(print_r($xml, true));
        return $body;
    }

        /**
     * Retrieve a specific record.
     *
     * @param string   $id     Record ID to retrieve
     * @param ParamBag $params Parameters
     *
     * @throws \Exception
     * @return array
     */
    public function getRecord($id, ParamBag $params = null)
    {
        $query = "AN " . $id;
        $params = $params ?: new ParamBag();
        $params->set('prof', $this->prof);
        $params->set('pwd', $this->pwd);
        $params->set('query', $query);
        $this->client->resetParameters();
        $response = $this->call('GET', $params->getArrayCopy(), false);
        $xml = simplexml_load_string($response);
        $finalDocs = array();
        foreach ($xml->SearchResults->records->rec as $doc) {
            $finalDocs[] = simplexml_load_string($doc->asXML());
        }
        return array(
            'docs' => $finalDocs,
            'offset' => 0,
            'total' => (integer)$xml->Hits
        );
    }
}
