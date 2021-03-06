<?php

/**
 * @file
 * Contains \OsRestfulSiteReport
 */
class OsRestfulSiteReport extends \OsRestfulReports {

  /**
   * @var string
   *
   * The timestamp representing the cutoff point for site content updates.
   */
  protected $latestUpdate = '';

  /**
   * @var array
   *
   * The content types that should not be included in the latest updated content search.
   */
  protected $excludedContentTypes = array();

  /**
   * {@inheritdoc}
   */
  public function publicFieldsInfo() {
    return array(
      'site_title' => array(
        'property' => 'title',
      ),
      'site_url' => array(
        'property' => 'id',
      ),
      'site_owner_email' => array(
        'property' => 'site_owner_email',
      ),
      'os_install' => array(
        'property' => 'os_install',
      ),
    );
  }

  public function runReport() {
    $request = $this->getRequest();
    if (isset($request['lastupdatebefore'])) {
      $this->latestUpdate = $request['lastupdatebefore'];
    }
    if (isset($request['exclude'])) {
      $this->excludedContentTypes = $request['exclude'];
    }

    $results = $this->getQueryForList()->execute();
    $return = array();

    foreach ($results as $result) {
      $return[] = $this->mapDbRowToPublicFields($result);
    }

    return $return;
  }

  /**
   * {@inheritdoc}
   *
   * add additional fields and table joins
   */
  public function getQueryForList() {
    global $base_url;

    $fields = $this->getPublicFields();
    $request = $this->getRequest();
    $query = db_select('purl');
    $query->addField('purl', 'id');
    $query->addField('purl', 'value');

    // site creation data
    if (isset($fields['site_created']) || isset($request['creationstart']) || isset($request['creationend'])) {
      $query->addField('n', 'created', 'site_created');
      $joinCondition = 'purl.id = n.nid AND provider = :provider';
      $arguments = array(':provider' => 'spaces_og');

      if (!isset($fields['site_created'])) {
        $fields['site_created'] = array('property' => 'site_created');
        $this->setPublicFields($fields);
      }
      if (isset($request['creationstart'])) {
        $joinCondition .= " AND n.created >= UNIX_TIMESTAMP(STR_TO_DATE(:startdate, '%Y%m%d'))";
        $arguments[':startdate'] = $request['creationstart'];
      }
      if (isset($request['creationend'])) {
        $joinCondition .= " AND n.created <= UNIX_TIMESTAMP(STR_TO_DATE(:enddate, '%Y%m%d'))";
        $arguments[':enddate'] = $request['creationend'];
      }

      $query->innerJoin('node', 'n', $joinCondition, $arguments);
    }
    else {
      $query->innerJoin('node', 'n', 'purl.id = n.nid AND provider = :provider', array(':provider' => 'spaces_og'));
    }

    $url_parts = explode(".", str_replace("http://", "", $base_url));
    $query->addExpression("'" . $url_parts[0] . "'", 'os_install');
    $query->addField('n', 'title');
    $query->addField('u', 'mail', 'site_owner_email');
    $query->innerJoin('users', 'u', 'u.uid = n.uid');

    // site content data
    if (!isset($request['includesites']) || $request['includesites'] == "all") {
      if ($this->latestUpdate || isset($fields['content_last_updated'])) {
        $query->addExpression('MAX(content.changed)', 'content_last_updated');
      }
      $query->leftJoin('og_membership', 'ogm', "ogm.gid = purl.id AND ogm.group_type = 'node' AND ogm.entity_type = 'node'");
      $query->leftJoin('node', 'content', "ogm.etid = content.nid and content.type NOT IN ('" . implode("','", $this->excludedContentTypes) . "')");
      $query->groupBy('purl.id, purl.value');
    }
    elseif (isset($fields['content_last_updated']) || $request['includesites'] == "content"){
      $query->addExpression('MAX(content.changed)', 'content_last_updated');
      $query->innerJoin('og_membership', 'ogm', "ogm.gid = purl.id AND ogm.group_type = 'node' AND ogm.entity_type = 'node'");
      $query->innerJoin('node', 'content', "ogm.etid = content.nid and content.type NOT IN ('" . implode("','", $this->excludedContentTypes) . "')");
      $query->groupBy('purl.id, purl.value');
    }
    elseif ($request['includesites'] == "nocontent"){
      $query->addExpression('COUNT(ogm.etid)', 'total');
      $query->leftJoin('og_membership', 'ogm', "ogm.gid = purl.id AND ogm.group_type = 'node' AND ogm.entity_type = 'node'");
      $query->groupBy('purl.id, purl.value');
      $query->havingCondition('total', '0', '=');
      if ($this->latestUpdate) {
        $fields['content_last_updated'] = array('property' => 'content_last_updated');
        $query->addExpression('NULL', 'content_last_updated');
        $this->setPublicFields($fields);
      }
    }

    // optional fields
    if (isset($fields['site_created_by'])) {
      // fields are converted to expressions to satisfy sql_mode ONLY_FULL_GROUP_BY
      $query->addExpression('MIN(creators.mail)', 'site_created_by');
      $subquery = db_select('og_membership','ogm')
                  ->condition('group_type', 'node', '=')
                  ->condition('entity_type', 'user', '=');
      $subquery->groupBy('ogm.gid');
      $subquery->addExpression('MIN(created)', 'date');
      $subquery->addExpression('MIN(etid)', 'etid');
      $subquery->addExpression('MIN(gid)', 'gid');
      $query->innerJoin($subquery, 'vsite_created', 'vsite_created.gid = purl.id');
      $query->innerJoin('users', 'creators', 'vsite_created.etid = creators.uid');
      $query->groupBy('purl.id, purl.value');
    }
    if ($this->latestUpdate && $request['includesites'] != "nocontent") {
      $query->havingCondition('content_last_updated', strtotime($this->latestUpdate), '<=');
      $fields['content_last_updated'] = array('property' => 'content_last_updated');
      $this->setPublicFields($fields);
    }
    if (isset($fields['site_privacy_setting'])) {
      // field is converted to expression to satisfy sql_mode ONLY_FULL_GROUP_BY
      $query->addExpression('MIN(access.group_access_value)', 'site_privacy_setting');
      $query->innerJoin('field_data_group_access', 'access', 'access.entity_id = purl.id');
    }
    if (isset($fields['owner_subdomain'])) {
      $query->addField('u', 'mail', 'owner_subdomain');
    }
    if (isset($fields['custom_domain'])) {
       // field is converted to expression to satisfy sql_mode ONLY_FULL_GROUP_BY
     $query->addExpression("'N'", 'custom_domain');
    }
    if (isset($fields['preset'])) {
      $query->addField('n', 'type', 'preset');
    }
    if (isset($fields['custom_theme_uploaded'])) {
      // field is converted to expression to satisfy sql_mode ONLY_FULL_GROUP_BY
      $query->addExpression('MIN(so.value)', 'custom_theme_uploaded');
      $query->leftJoin('spaces_overrides', 'so', "so.id = purl.id and so.type = 'og' and so.object_type = 'variable' AND so.object_id = 'flavors' and so.value <> :empty", array(':empty' => 'a:0:{}'));
    }

    $this->queryForListSort($query);
    $this->queryForListFilter($query);
    $this->queryForListPagination($query);
    $this->addExtraInfoToQuery($query);

    return $query;
  }

  /**
   * {@inheritdoc}
   *
   * adds logic to handle site roles and latest updated content, if needed
   */
  public function mapDbRowToPublicFields($row) {
    global $base_url;
    $new_row = parent::mapDbRowToPublicFields($row);

    // format dates
    if (isset($new_row['content_last_updated'])) {
      if ($new_row['content_last_updated']) {
        $new_row['content_last_updated'] = date('M j Y h:ia', $row->content_last_updated);
      }
    }
    if (isset($new_row['site_created'])) {
      $new_row['site_created'] = date('M j Y h:ia', $row->site_created);
    }

    // tease out subdomain from email address
    if (isset($new_row['owner_subdomain'])) {
      $domain_parts = explode(".", preg_replace('/.*@/', "", $new_row['owner_subdomain']));
      if (count($domain_parts) > 2 && in_array("harvard", $domain_parts) && in_array("edu", $domain_parts)) {
        $new_row['owner_subdomain'] = implode(" ", array_slice($domain_parts, -4, count($domain_parts) - 2));
      }
      else {
        $new_row['owner_subdomain'] = "";
      }
    }

    // check for custom domain
    $row->customdomain = db_select('spaces_overrides', 'so')
                          ->fields('so', array('value'))
                          ->condition('id', $row->id, '=')
                          ->condition('type', 'og', '=')
                          ->condition('object_id', 'vsite_domain_name', '=')
                          ->condition('object_type', 'variable', '=')
                          ->condition('value', 'N;', '<>')
                          ->condition('value', 's:0:"";', '<>')
                          ->execute()
                          ->fetchField();
    if ($row->customdomain) {
      $new_row['site_url'] = "http://" . unserialize($row->customdomain) . "/" . $row->value;
      if (isset($row->custom_domain)) {
        $new_row['custom_domain'] = 'Y';
      }
    }
    else {
      $new_row['site_url'] = $base_url . "/" . $row->value;
    }

    // optional preset column
    if(isset($new_row['preset'])) {
      $preset_serialized = db_select('spaces_overrides', 'preset')
                            ->fields('preset', array('value'))
                            ->condition('id', $row->id, '=')
                            ->condition('type', 'og', '=')
                            ->condition('object_id', 'spaces_preset_og', '=')
                            ->condition('object_type', 'variable', '=')
                            ->execute()
                            ->fetchField();
      if ($preset_serialized) {
        $new_row['preset'] .= " (" . str_replace("_", " ", unserialize($preset_serialized)) . ")";
      }
      else {
        $new_row['preset'] .= " (minimal)";
      }
    }

    // optional privacy column
    if (isset($new_row['site_privacy_setting'])) {
      $privacy_values = array(
        '0' => 'Public on the web.',
        '1' => 'Invite only during site creation.',
        '2' => 'Anyone with the link.',
        '4' => 'Harvard Community'
      );
      $new_row['site_privacy_setting'] = $privacy_values[$row->site_privacy_setting];
    }

    // optional custom theme uploaded column
    if (isset($new_row['custom_theme_uploaded'])) {
      if ($new_row['custom_theme_uploaded']) {
        $new_row['custom_theme_uploaded'] = "Y";
      }
      else {
        $new_row['custom_theme_uploaded'] = "N";
      }
    }

    return $new_row;
  }
}
