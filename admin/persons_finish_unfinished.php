<?php
#----------------------------------------------------------------------
#   Initialization and page contents.
#----------------------------------------------------------------------

require( '../_header.php' );

showDescription();
getPersonsFromResults();
showUnfinishedPersons();

require( '../_footer.php' );

#----------------------------------------------------------------------
function showDescription () {
#----------------------------------------------------------------------

  echo "<p><b>This script does *NOT* affect the database unless you say so.</b></p>";

  echo "<p>In this script, a \"person\" always means a triple of id/name/countryId, and \"similar\" always means just name similarity. A person is called \"finished\" if it has a non-empty personId. A \"semi-id\" is the id without the running number at the end.</p>";

  echo "<p>For each unfinished person in the Results table, I show you the few most similar persons. Then you make choices and click \"update\" at the bottom of the page to show and execute your choices. You can:</p>";

  echo "<ul>";
  echo "<li>Choose the person as \"new\", optionally modifying name, country and semi-id. This will add the person to the Persons table (with appropriately extended id) and change its Results accordingly.</li>";
  echo "<li>Choose another person. This will overwrite the person's name/country/id in the Results table with those of the other person.</li>";
  echo "<li>Skip it if you're not sure yet.</li>";
  echo "</ul>";

  echo "<p>Notice for time limit reasons I can't show you all unfinished persons at once, so I only show up to 20 at a time. After clicking \"Update\" you'll see all commands I execute and a link back to this script with a random parameter in order to really reload this script. Then you should see the next up to 20 unfinished persons, and you can repeat until there are none left.";
  
  echo "<hr>";
}

#----------------------------------------------------------------------
function getPersonsFromResults () {
#----------------------------------------------------------------------
  global $personsFromResults;

  $persons = dbQuery("
    SELECT personId id, personName name, result.countryId, min(year) firstYear
    FROM Results result, Competitions competition
    WHERE competition.id = competitionId
    GROUP BY BINARY personId, BINARY personName, BINARY result.countryId
  ");
  foreach( $persons as $person ){
    extract( $person );
    $personsFromResults["$id/$name/$countryId"] = $person;
#    echo "[$id]";
  }
}

#----------------------------------------------------------------------
function showUnfinishedPersons () {
#----------------------------------------------------------------------
  global $personsFromResults;

  #--- Begin the form and table.
  echo "<form action='persons_finish_unfinished_ACTION.php' method='POST'>";
  tableBegin( 'results', 7 );
  tableHeader( split( '\\|', '|personName|countryId|personId|personName|countryId|personSemiId' ),
               array( 5=>'class="6"' ) );

  #--- Walk over all persons from the Results table.
  foreach( $personsFromResults as $person ){
    extract( $person );
    
    #--- If the person is finished, skip it.
    if( $id )
      continue;

#if( ! $nameHtml )
#  $name = 'Andy\'s Taso';
  
    #--- Try to compute the semi-id.
    $semiId = $firstYear . strtoupper( substr( preg_replace( '/\W/', '', $name ), 0, 4 ));

    #--- Html-ify name and country.
    $nameHtml = htmlEscape( $name );
    $countryIdHtml = htmlEscape( $countryId );

    #--- Hidden field describing the case.
    $caseNr++;
    tableRowFull( "&nbsp;<input type='hidden' name='oldNameAndCountry$caseNr' value='$nameHtml|$countryIdHtml' />" );
    
    #--- Show the person.
    tableRowStyled( 'font-weight:bold', array(
      "<input type='radio' name='action$caseNr' value='new' />",
      visualize( $name ),
      visualize( $countryId ),
      '',
      "<input type='text' name='name$caseNr' value='$nameHtml' size='20' />",
      "<input type='text' name='country$caseNr' value='$countryIdHtml' size='20' />",
      "<input type='text' name='semiId$caseNr' value='$semiId' size='10' maxlength='8' />",
    ));

    #--- Show most similar persons.
    $similarsCtr = 0;
    foreach( getMostSimilarPersonsMax( $name, $countryId, $personsFromResults, 10 ) as $similarPerson ){
      extract( $similarPerson, EXTR_PREFIX_ALL, 'other' );
      
      #--- If name and country match the unfinished persons, pre-select it.
      $checked = ($other_name==$name && $other_countryId==$countryId)
        ? "checked='checked'" : '';
        
      #--- Skip the unfinished person itself. 
      if( $checked && !$other_id )
        continue;

      #--- Html-ify.
      $nameHtml = htmlEscape( $other_name );
      $countryHtml = htmlEscape( $other_countryId );
      $idHtml = htmlEscape( $other_id );
      
      #--- Use "name|country|id" as action.
      $action = "$nameHtml|$countryHtml|$idHtml";
      
      #--- Show the other person.
      tableRow( array(
        "<input type='radio' name='action$caseNr' value='$action' $checked />",
#        ($other_id ? personLink( $other_id, $other_name ) : $other_name),
        visualize( $other_name ),
        visualize( $other_countryId ),
        visualize( $other_id ),
        '', #sprintf( "%.2f", $similarity ),
        '',
        '',
      ));
      
      #--- Stop after five similar persons.
      if( ++$similarsCtr == 5 )
        break;
    }

    #--- Offer an explicit skip.
    tableRow( array(
      "<input type='radio' name='action$caseNr' value='skip' />",
      'I\'m not sure yet', '', '', '', '', ''
    ));
    
    #--- Don't show more than 20 unfinished persons.
    if( ++$ctr == 20 )
      break;
  }

  #--- Show 'Update' button, finish table and form.
  tableRowEmpty();
  tableRowFull( "<input type='submit' value='Update' />" );
  tableEnd();
  echo "</form>";
}

#----------------------------------------------------------------------
function getMostSimilarPersons ( $name, $countryId, $persons ) {
#----------------------------------------------------------------------
  return getMostSimilarPersonsMax( $name, $countryId, $persons, 4 );
}

#----------------------------------------------------------------------
function getMostSimilarPersonsMax ( $name, $countryId, $persons, $max ) {
#----------------------------------------------------------------------

  #--- Compute similarities to all persons.
  foreach( $persons as $other ) {
    extract( $other, EXTR_PREFIX_ALL, 'other' );
    similar_text( $name, $other_name, $similarity );
    $other['similarity'] = $similarity;
    similar_text( $countryId, $other_countryId, $similarity );
    $other['countrySimilarity'] = $similarity;
    $candidates[] = $other;
  }

#print_r( $candidates );
  #--- Sort candidates and return up to three most promising.
  usort( $candidates, 'compareCandidates' );
  return array_slice( $candidates, 0, $max );
}

#----------------------------------------------------------------------
function compareCandidates ( $a, $b ) {
#----------------------------------------------------------------------

  if( $a['similarity'] > $b['similarity'] ) return -1;
  if( $a['similarity'] < $b['similarity'] ) return 1;

  if( $a['countrySimilarity'] > $b['countrySimilarity'] ) return -1;
  if( $a['countrySimilarity'] < $b['countrySimilarity'] ) return 1;

  if( $a['id'] ) return -1;
  if( $b['id'] ) return 1;
#echo( "[$a[id]]" );
#echo( "($b[id])" );
  return 0;
}

#----------------------------------------------------------------------
function visualize ( $text ) {
#----------------------------------------------------------------------

  return preg_replace( '/\s/', '<span style="color:#F00">#</span>', $text );
}

#----------------------------------------------------------------------
function highlight ( $sql ) {
#----------------------------------------------------------------------
  $sql = preg_replace( '/(UPDATE|SET|WHERE|AND|REGEXP)/', '<b>$1</b>', $sql );
  $sql = preg_replace( '/(\\w+)=\'(.*?)\'/', '<span style="color:#00C">$1</span>=\'<span style="color:#F00">$2</span>\'', $sql );
  return $sql;
}

?>
