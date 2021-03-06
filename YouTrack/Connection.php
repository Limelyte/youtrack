<?php
namespace YouTrack;

/**
 * A class for connecting to a YouTrack instance.
 *
 * @internal revision
 * 20120318 - francisco.mancardi@gmail.com
 * new method get_global_issue_states()
 * Important Notice
 * REST API documentation for version 3.x this method is not documented.
 * REST API documentation for version 2.x this method is DOCUMENTED.
 * (http://confluence.jetbrains.net/display/YTD2/Get+Issue+States)
 *
 * new method get_state_bundle()
 *
 * @author Jens Jahnke <jan0sch@gmx.net>
 * Created at: 29.03.11 16:13
 *
 * @see http://confluence.jetbrains.com/display/YTD5/YouTrack+REST+API+Reference
 */
class Connection
{
    private $http = null;
    private $url = '';
    private $loginName;
    private $base_url = '';
    private $headers = array();
    private $cookies = array();
    private $debug_verbose = false; // Set to TRUE to enable verbose logging of curl messages.
    private $user_agent = 'Mozilla/5.0'; // Use this as user agent string.
    private $verify_ssl = false;

    public function __construct($url, $login, $password)
    {
        $this->http = curl_init();
        $this->url = $url;
        $this->base_url = $url . '/rest';
        $this->loginName = $login;
        $this->login($login, $password);
    }


    /**
     * Checks if the connection is via HTTPS
     *
     * @return bool
     */
    public function isHttps()
    {
        if (!empty($this->url)) {

            $url = strtolower($this->url);
            if (substr($url, 0, strlen('https')) == 'https') {
                return true;
            }
        }
        return false;
    }

    /**
    * Loop through the given array and remove all entries
    * that have no value assigned.
    *
    * @param array &$params The array to inspect and clean up.
    */
    private function cleanUrlParameters(&$params)
    {
        if (!empty($params) && is_array($params)) {
            foreach ($params as $key => $value) {
                if (empty($value)) {
                    unset($params["$key"]);
                }
            }
        }
    }

    /**
     * @param string $username
     * @param string $password
     * @throws Exception
     */
    protected function login($username, $password)
    {
        curl_setopt($this->http, CURLOPT_POST, true);
        curl_setopt($this->http, CURLOPT_HTTPHEADER, array('Content-Length: 1')); // Workaround for login problems when running behind lighttpd proxy @see http://redmine.lighttpd.net/issues/1717
        curl_setopt($this->http, CURLOPT_URL, $this->base_url . '/user/login?login='. urlencode($username) .'&password='. urlencode($password));
        curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->http, CURLOPT_HEADER, true);
        curl_setopt($this->http, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);
        curl_setopt($this->http, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($this->http, CURLOPT_VERBOSE, $this->debug_verbose);
        curl_setopt($this->http,CURLOPT_POSTFIELDS, "a");
        $content = curl_exec($this->http);
        $response = curl_getinfo($this->http);
        if ((int) $response['http_code'] != 200) {
            throw new Exception('/user/login', $response, $content);
        }
        $cookies = array();
        preg_match_all('/^Set-Cookie: (.*?)=(.*?)$/sm', $content, $cookies, PREG_SET_ORDER);
        foreach($cookies as $cookie) {
            $parts = parse_url($cookie[0]);
            $this->cookies[] = $parts['path'];
        }
        $this->headers[CURLOPT_HTTPHEADER] = array('Cache-Control: no-cache');
        curl_close($this->http);
    }

    /**
    * Execute a request with the given parameters and return the response.
    *
    * @throws \Exception|Exception An exception is thrown if an error occurs.
    * @param string $method The http method (GET, PUT, POST).
    * @param string $url The request url.
    * @param string $body Data that should be send or the filename of the file if PUT is used.
    * @param int $ignore_status Ignore the given http status code.
    * @return array An array holding the response content in 'content' and the response status
    * in 'response'.
    */
    protected function request($method, $url, $body = null, $ignore_status = 0)
    {
        if (substr($url, 0, strlen('http://')) != 'http://'
            && substr($url, 0, strlen('https://')) != 'https://'
        ) {

            $url = $this->base_url . $url;
        }
        $this->http = curl_init($url);
        $headers = $this->headers;
        if ($method == 'PUT' || $method == 'POST') {

            if (!file_exists($body)) {
                $headers[CURLOPT_HTTPHEADER][] = 'Content-Type: application/xml; charset=UTF-8';
                $headers[CURLOPT_HTTPHEADER][] = 'Content-Length: '. mb_strlen($body);
            }
        }
        switch ($method) {
            case 'GET':
                curl_setopt($this->http, CURLOPT_HTTPGET, true);
                break;
            case 'PUT':
                $handle = null;
                $size = 0;
                // Check if we got a file or just a string of data.
                if (file_exists($body)) {
                    $size = filesize($body);
                    if (!$size) {
                        throw new \Exception("Can't open file $body!");
                    }
                    $handle = fopen($body, 'r');
                } else {
                    $size = mb_strlen($body);
                    $handle = fopen('data://text/plain,' . $body,'r');
                }
                curl_setopt($this->http, CURLOPT_PUT, true);
                curl_setopt($this->http, CURLOPT_INFILE, $handle);
                curl_setopt($this->http, CURLOPT_INFILESIZE, $size);
                break;
            case 'POST':
                curl_setopt($this->http, CURLOPT_POST, true);
                if (!empty($body)) {

                    if (file_exists($body)) {

                        if (version_compare(PHP_VERSION, '5.5', '>=')
                            && class_exists('\\CURLFile')
                        ) {
                            $file = new \CURLFile($body);
                        } else {
                            $file = '@' . $body;
                        }
                        $body = array(
                            'file' => $file
                        );
                    }
                    curl_setopt($this->http, CURLOPT_POSTFIELDS, $body);
                }
            break;
            default:
                throw new \Exception("Unknown method $method!");
        }
        curl_setopt($this->http, CURLOPT_HTTPHEADER, $headers[CURLOPT_HTTPHEADER]);
        curl_setopt($this->http, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->http, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);
        curl_setopt($this->http, CURLOPT_VERBOSE, $this->debug_verbose);
        curl_setopt($this->http, CURLOPT_COOKIE, implode(';', $this->cookies));
        $content = curl_exec($this->http);
        $response = curl_getinfo($this->http);
        curl_close($this->http);

        if ((int) $response['http_code'] != 200 &&
            (int) $response['http_code'] != 201 &&
            (int) $response['http_code'] != $ignore_status) {
            throw new Exception($url, $response, $content);
        }

        // for fetching results for test data
        /*if (!empty($content)) {
            file_put_contents(md5($content).'.xml', $content);
        }*/

        return array(
            'content' => $content,
            'response' => $response,
        );
    }

    protected function requestXml($method, $url, $body = null, $ignore_status = 0)
    {
        $r = $this->request($method, $url, $body, $ignore_status);
        $response = $r['response'];
        $content = $r['content'];
        if (!empty($response['content_type'])) {
            if (preg_match('/application\/xml/', $response['content_type']) || preg_match('/text\/xml/', $response['content_type'])) {
                return simplexml_load_string($content);
            }
        }
        return $content;
    }

    protected function get($url)
    {
        return $this->requestXml('GET', $url);
    }

    protected function put($url)
    {
        return $this->requestXml('PUT', $url, '<empty/>\n\n');
    }

    public function getIssue($id)
    {
        $issue = $this->get('/issue/' . $id);
        return new Issue($issue, $this);
    }

    /**
     * creates an issue with properties from $params
     *
     * may be this is an general $params value:
     * <code>
     *  $params = array(
        'project' => (string)$project,
        'assignee' => (string)$assignee,
        'summary' => (string)$summary,
        'description' => (string)$description,
        'priority' => (string)$priority,
        'type' => (string)$type,
        'subsystem' => (string)$subsystem,
        'state' => (string)$state,
        'affectsVersion' => (string)$affectsVersion,
        'fixedVersion' => (string)$fixedVersion,
        'fixedInBuild' => (string)$fixedInBuild,
        );
     * </code>
     *
     * @param string $project the obligatory project name
     * @param string $summary the obligatory issue summary
     * @param array $params optional additional parameters for the new issue (look into your personal youtrack instance!)
     * @return Issue
     */
    public function createIssue($project, $summary, $params = array())
    {

        $params['project'] = (string)$project;
        $params['summary'] = (string)$summary;
        array_walk($params, function (&$value) {
            // php manual: If funcname needs to be working with the actual values of the array,
            //  specify the first parameter of funcname as a reference. Then, any changes made to
            //  those elements will be made in the original array itself.
            $value = (string)$value;
        });
        $issue = $this->requestXml('POST', '/issue?'. http_build_query($params));
        return new Issue($issue, $this);
    }

    public function getAccessibleProjects()
    {
        $xml = $this->get('/project/all');
        $projects = array();

        foreach ($xml->children() as $node) {
            $node = new Project(new \SimpleXMLElement($node->asXML()));
            $projects[] = $node;
        }
        return $projects;
    }

    public function getComments($id)
    {
        $comments = array();
        $req = $this->request('GET', '/issue/'. urlencode($id) .'/comment');
        $xml = simplexml_load_string($req['content']);
        foreach($xml->children() as $node) {
            $comments[] = new Comment($node, $this);
        }
        return $comments;
    }


    /**
     * @param $id
     * @return Attachment[]
     */
    public function getAttachments($id)
    {
        $attachments = array();
        $req = $this->request('GET', '/issue/'. urlencode($id) .'/attachment');
        $xml = simplexml_load_string($req['content']);
        foreach($xml->children() as $node) {
            $attachments[] = new Attachment($node, $this);
        }
        return $attachments;
    }


    /**
     * Returns the file content from the given attachment url
     *
     * @param string $url The attachment url
     *
     * @return bool
     */
    public function getAttachmentContent($url)
    {
        $result = $this->request('GET', $url);

        if ($result['response']['http_code'] == 200) {

            return $result['content'];
        }
        return false;
    }


    /**
     * @param string $issueId The issue id
     * @param Attachment $attachment The attachment
     * @return array
     */
    public function createAttachmentFromAttachment($issueId, Attachment $attachment)
    {
        $params = array(
            // 'group' => '',
            // 'name' => '',
            // 'authorLogin' => '',
            // 'created' => time()*1000
        );


        if ($attachment->getGroup()) {
            $params['group'] = $attachment->getGroup();
        }
        if ($attachment->getName()) {
            $params['name'] = $attachment->getName();
        }
        if ($attachment->getAuthorLogin()) {
            $params['authorLogin'] = $attachment->getAuthorLogin();
        }
        if ($attachment->getCreated()) {
            $created = $attachment->getCreated();
            if ($created instanceof \DateTime) {
                $created = $created->getTimestamp()*1000;
            }
            $params['created'] = $created;
        }

        return $this->request(
            'POST',
            '/issue/'. urlencode($issueId) .'/attachment?' . http_build_query($params),
            $attachment->getUrl()
        );
    }

    /**
     * @param string $issueId
     * @param bool $outward_only
     * @return Link[]
     * @throws \Exception
     */
    public function getLinks($issueId , $outward_only = false)
    {
        $links = array();
        $req = $this->request('GET', '/issue/'. urlencode($issueId) .'/link');
        $xml = simplexml_load_string($req['content']);
        foreach($xml->children() as $node) {
            if (($node->attributes()->source != $issueId) || !$outward_only) {
                $links[] = new Link($node, $this);
            }
        }
        return $links;
    }

    public function getUser($login) {
        return new User($this->get('/admin/user/'. urlencode($login)));
    }

    public function createUser($user) {
        $this->importUsers(array($user));
    }

    public function createUserDetailed($login, $full_name, $email, $jabber) {
        $this->importUsers(array(array('login' => $login, 'fullName' => $full_name, 'email' => $email, 'jabber' => $jabber)));
    }

    public function importUsers($users) {
        if (count($users) <= 0) {
            return;
        }
        $xml = "<list>\n";
        foreach ($users as $user) {
            $xml .= "  <user";
            foreach ($user as $key => $value) {
                $xml .= " $key=". urlencode($value);
            }
            $xml .= " />\n";
        }
        $xml .= "</list>";
        return $this->requestXml('PUT', '/import/users', $xml, 400);
    }

    public function importIssuesXml($project_id, $assignee_group, $xml) {
        throw new NotImplementedException("import_issues_xml(project_id, assignee_group, xml)");
    }

    public function importLinks($links) {
        throw new NotImplementedException("import_links(links)");
    }

    public function importIssues($project_id, $assignee_group, $issues) {
        throw new NotImplementedException("import_issues(project_id, assignee_group, issues)");
    }

    public function getProject($project_id) {
        return new Project($this->get('/admin/project/'. urlencode($project_id)));
    }

    public function getProjectAssigneeGroups($project_id) {
        $xml = $this->get('/admin/project/'. urlencode($project_id) .'/assignee/group');
        $groups = array();
        foreach ($xml->children() as $group) {
            $groups[] = new Group(new \SimpleXMLElement($group->asXML()));
        }
        return $groups;
    }

    public function getGroup($name) {
        return new Group($this->get('/admin/group/'. urlencode($name)));
    }

    public function getUserGroups($login) {
        $xml = $this->get('/admin/user/'. urlencode($login) .'/group');
        $groups = array();
        foreach ($xml->children() as $group) {
            $groups[] = new Group(new \SimpleXMLElement($group->asXML()));
        }
        return $groups;
    }

    public function setUserGroup($login, $group_name) {
        $r = $this->request('POST', '/admin/user/'. urlencode($login) .'/group/'. urlencode($group_name));
        return $r['response'];
    }

    public function createGroup(Group $group)
    {
        $r = $this->put('/admin/group/' . urlencode($group->name) . '?description=noDescription&autoJoin=false');
        return $r['response'];
    }

    public function getRole($name)
    {
        return new Role($this->get('/admin/role/' . urlencode($name)));
    }

    public function getSubsystem($project_id, $name)
    {
        return new Subsystem($this->get('/admin/project/' . urlencode($project_id) . '/subsystem/' . urlencode($name)));
    }

    public function getSubsystems($project_id)
    {
        $xml = $this->get('/admin/project/' . urlencode($project_id) . '/subsystem');
        $subsystems = array();
        foreach ($xml->children() as $subsystem) {
            $subsystems[] = new Subsystem(new \SimpleXMLElement($subsystem->asXML()));
        }
        return $subsystems;
    }

    public function getVersions($project_id)
    {
        $xml = $this->get('/admin/project/' . urlencode($project_id) . '/version?showReleased=true');
        $versions = array();
        foreach ($xml->children() as $version) {
            $versions[] = new Version(new \SimpleXMLElement($version->asXML()));
        }
        return $versions;
    }

    public function getVersion($project_id, $name)
    {
        return new Version($this->get('/admin/project/' . urlencode($project_id) . '/version/' . urlencode($name)));
    }

    public function getBuilds($project_id)
    {
        $xml = $this->get('/admin/project/' . urlencode($project_id) . '/build');
        $builds = array();
        foreach ($xml->children() as $build) {
            $builds[] = new Build(new \SimpleXMLElement($build->asXML()));
        }
        return $builds;
    }

    public function getUsers($q = '')
    {
        $users = array();
        $q = trim((string)$q);
        $params = array(
            'q' => $q,
        );
        $this->cleanUrlParameters($params);
        $xml = $this->get('/admin/user/?' . http_build_query($params));
        if (!empty($xml) && is_object($xml)) {
            foreach ($xml->children() as $user) {
                $users[] = new User(new \SimpleXMLElement($user->asXML()));
            }
        }
        return $users;
    }

    public function createBuild()
    {
        throw new NotImplementedException("create_build()");
    }

    public function createBuilds()
    {
        throw new NotImplementedException("create_builds()");
    }

    public function createProject($project)
    {
        return $this->createProjectDetailed($project->id, $project->name, $project->description, $project->leader);
    }

    public function createProjectDetailed(
        $project_id,
        $project_name,
        $project_description,
        $project_lead_login,
        $starting_number = 1
    ) {
        $params = array(
            'projectName' => (string)$project_name,
            'description' => (string)$project_description,
            'projectLeadLogin' => (string)$project_lead_login,
            'lead' => (string)$project_lead_login,
            'startingNumber' => (string)$starting_number,
        );
        return $this->put('/admin/project/' . urlencode($project_id) . '?' . http_build_query($params));
    }

    public function createSubsystems($project_id, $subsystems)
    {
        foreach ($subsystems as $subsystem) {
            $this->createSubsystem($project_id, $subsystem);
        }
    }

    public function createSubsystem($project_id, $subsystem)
    {
        return $this->createSubsystemDetailed(
            $project_id,
            $subsystem->name,
            $subsystem->isDefault,
            $subsystem->defaultAssignee
        );
    }

    public function createSubsystemDetailed($project_id, $name, $is_default, $default_assignee_login)
    {
        $params = array(
            'isDefault' => (string)$is_default,
            'defaultAssignee' => (string)$default_assignee_login,
        );
        $this->put(
            '/admin/project/' . urlencode($project_id) . '/subsystem/' . urlencode($name) . '?' . http_build_query(
                $params
            )
        );
        return 'Created';
    }

    public function deleteSubsystem($project_id, $name)
    {
        return $this->requestXml(
            'DELETE',
            '/admin/project/' . urlencode($project_id) . '/subsystem/' . urlencode($name)
        );
    }

    public function createVersions($project_id, $versions)
    {
        foreach ($versions as $version) {
            $this->createVersion($project_id, $version);
        }
    }

    public function createVersion($project_id, $version)
    {
        return $this->createVersionDetailed(
            $project_id,
            $version->name,
            $version->isReleased,
            $version->isArchived,
            $version->releaseDate,
            $version->description
        );
    }

    public function createVersionDetailed(
        $project_id,
        $name,
        $is_released,
        $is_archived,
        $release_date = null,
        $description = ''
    ) {
        $params = array(
            'description' => (string)$description,
            'isReleased' => (string)$is_released,
            'isArchived' => (string)$is_archived,
        );
        if (!empty($release_date)) {
            $params['releaseDate'] = $release_date;
        }
        return $this->put(
            '/admin/project/' . urldecode($project_id) . '/version/' . urlencode($name) . '?' . http_build_query(
                $params
            )
        );
    }
    
    /**
     * http://confluence.jetbrains.com/display/YTD5/Get+Number+of+Issues+for+Several+Queries
     */
    public function executeCountQueries(array $queries)
    {
        $body = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><queries>';
        foreach ($queries as $query) {
            $body .= '<query>'.$query.'</query>';
        }
        $body .= '</queries>';

        $r = $this->request('POST', '/issue/counts?rough=false&sync=true', $body);
        $content = simplexml_load_string($r['content']);

        return $content;
    }

    public function getIssues($project_id, $filter, $after, $max)
    {
        $params = array(
            'after' => (string)$after,
            'max' => (string)$max,
            'filter' => (string)$filter,
        );
        $this->cleanUrlParameters($params);
        $xml = $this->get('/project/issues/' . urldecode($project_id) . '?' . http_build_query($params));
        $issues = array();
        foreach ($xml->children() as $issue) {
            $issues[] = new Issue(new \SimpleXMLElement($issue->asXML()), $this);
        }
        return $issues;
    }

    public function executeCommand($issue_id, $command, $comment = null, $group = null)
    {
        $params = array(
            'command' => (string)$command,
        );
        if (!empty($comment)) {
            $params['comment'] = (string)$comment;
        }
        if (!empty($group)) {
            $params['group'] = (string)$group;
        }
        $r = $this->request('POST', '/issue/' . urlencode($issue_id) . '/execute?' . http_build_query($params));
        return 'Command executed';
    }

    public function getCustomField($name)
    {
        return new CustomField($this->get('/admin/customfield/field/' . urlencode($name)));
    }

    public function getCustomFields()
    {
        $xml = $this->get('/admin/customfield/field');
        $fields = array();
        foreach ($xml->children() as $field) {
            $fields[] = new CustomField(new \SimpleXMLElement($field->asXML()));
        }
        return $fields;
    }

    public function createCustomFields($fields)
    {
        foreach ($fields as $field) {
            $this->createCustomField($field);
        }
    }

    public function createCustomField($field)
    {
        return $this->createCustomFieldDetailed(
            $field->name,
            $field->type,
            $field->isPrivate,
            $field->visibleByDefault
        );
    }

    public function createCustomFieldDetailed($name, $type_name, $is_private, $default_visibility)
    {
        $params = array(
            'typeName' => (string)$type_name,
            'isPrivate' => (string)$is_private,
            'defaultVisibility' => (string)$default_visibility,
        );
        $this->put('/admin/customfield/field/' . urlencode($name) . '?' . http_build_query($params));
        return 'Created';
    }

    public function getEnumBundle($name)
    {
        return new EnumBundle($this->get('/admin/customfield/bundle/' . urlencode($name)));
    }

    public function createEnumBundle(EnumBundle $bundle)
    {
        return $this->requestXml('PUT', '/admin/customfield/bundle', $bundle->toXML(), 400);
    }

    public function deleteEnumBundle($name)
    {
        $r = $this->request('DELETE', '/admin/customfield/bundle/' . urlencode($name), '');
        return $r['content'];
    }

    public function addValueToEnumBundle($name, $value)
    {
        return $this->put('/admin/customfield/bundle/' . urlencode($name) . '/' . urlencode($value));
    }

    public function addValuesToEnumBundle($name, $values)
    {
        foreach ($values as $value) {
            $this->addValueToEnumBundle($name, $value);
        }
        return implode(', ', $values);
    }

    public function getProjectCustomField($project_id, $name)
    {
        return new CustomField(
            $this->get('/admin/project/' . urlencode($project_id) . '/customfield/' . urlencode($name))
        );
    }

    public function getProjectCustomFields($project_id)
    {
        $xml = $this->get('/admin/project/' . urlencode($project_id) . '/customfield');
        $fields = array();
        foreach ($xml->children() as $cfield) {
            $fields[] = new CustomField(new \SimpleXMLElement($cfield->asXML()));
        }
        return $fields;
    }

    public function createProjectCustomField($project_id, CustomField $pcf)
    {
        return $this->createProjectCustomFieldDetailed($project_id, $pcf->name, $pcf->emptyText, $pcf->params);
    }

    private function createProjectCustomFieldDetailed($project_id, $name, $empty_field_text, $params = array())
    {
        $_params = array(
            'emptyFieldText' => (string)$empty_field_text,
        );
        if (!empty($params)) {
            $_params = array_merge($_params, $params);
        }
        return $this->put(
            '/admin/project/' . urlencode($project_id) . '/customfield/' . urlencode($name) . '?' . http_build_query(
                $_params
            )
        );
    }

    public function getIssueLinkTypes()
    {
        $xml = $this->get('/admin/issueLinkType');
        $lts = array();
        foreach ($xml->children() as $node) {
            $lts[] = new IssueLinkType(new \SimpleXMLElement($node->asXML()));
        }
        return $lts;
    }

    public function createIssueLinkTypes($lts)
    {
        foreach ($lts as $lt) {
            $this->createIssueLinkType($lt);
        }
    }

    public function createIssueLinkType($ilt)
    {
        return $this->createIssueLinkTypeDetailed($ilt->name, $ilt->outwardName, $ilt->inwardName, $ilt->directed);
    }

    public function createIssueLinkTypeDetailed($name, $outward_name, $inward_name, $directed)
    {
        $params = array(
            'outwardName' => (string)$outward_name,
            'inwardName' => (string)$inward_name,
            'directed' => (string)$directed,
        );
        return $this->put('/admin/issueLinkType/' . urlencode($name) . '?' . http_build_query($params));
    }

    public function getVerifySsl()
    {
        return $this->verify_ssl;
    }

    /**
    * Use this method to enable or disable the ssl_verifypeer option of curl.
    * This is usefull if you use self-signed ssl certificates.
    *
    * @param bool $verify_ssl
    * @return void
    */
    public function setVerifySsl($verify_ssl) {
        $this->verify_ssl = $verify_ssl;
    }

    /**
    * get pairs (state,revolved attribute) in hash.
    * same info is get online on:
    * Project Fields › States (Click to change bundle name)
    *
    * @return null|array hash key: state string
    *              value: true is resolved attribute set to true
    */
    public function getGlobalIssueStates()
    {
        $xml = $this->get('/project/states');
        $states = null;
        foreach($xml->children() as $node) {
            $states[(string)$node['name']] = ((string)$node['resolved'] == 'true');
        }
        return $states;
    }

    /**
    * useful when you have configured different states for different projects
    * in this cases you will create bundles with name with global scope,
    * i.e. name can not be repeated on youtrack installation.
    *
    * @param string $name
    * @return hash key: state string
    *			  value: hash('description' => string, 'isResolved' => boolean)
    */
    public function getStateBundle($name)
    {
        $cmd = '/admin/customfield/stateBundle/' . urlencode($name);
        $xml = $this->get($cmd);
        $bundle = null;
        foreach($xml->children() as $node) {
            $bundle[(string)$node] = array(
                'description' => (isset($node['description']) ? (string)$node['description'] : ''),
                'isResolved' => ((string)$node['isResolved']=='true')
            );
        }
        return $bundle;
    }
}
