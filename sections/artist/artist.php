<?
//~~~~~~~~~~~ Main artist page ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~//

//For sorting tags
function compare($X, $Y) {
	return($Y['count'] - $X['count']);
}

include(SERVER_ROOT.'/classes/text.class.php'); // Text formatting class
$Text = new TEXT;

// Similar artist map
include(SERVER_ROOT.'/classes/artists_similar.class.php');

$UserVotes = Votes::get_user_votes($LoggedUser['ID']);

$ArtistID = $_GET['id'];
if (!is_number($ArtistID)) {
	error(0);
}


if (!empty($_GET['revisionid'])) { // if they're viewing an old revision
	$RevisionID = $_GET['revisionid'];
	if (!is_number($RevisionID)) {
		error(0);
	}
	$Data = $Cache->get_value("artist_{$ArtistID}_revision_$RevisionID", true);
} else { // viewing the live version
	$Data = $Cache->get_value("artist_$ArtistID", true);
	$RevisionID = false;
}

if ($Data) {
	list($K, list($Name, $Image, $Body, $NumSimilar, $SimilarArray, , , $VanityHouseArtist)) = each($Data);
} else {
	if ($RevisionID) {
		$sql = "
			SELECT
				a.Name,
				wiki.Image,
				wiki.body,
				a.VanityHouse
			FROM wiki_artists AS wiki
				LEFT JOIN artists_group AS a ON wiki.RevisionID = a.RevisionID
			WHERE wiki.RevisionID = '$RevisionID' ";
	} else {
		$sql = "
			SELECT
				a.Name,
				wiki.Image,
				wiki.body,
				a.VanityHouse
			FROM artists_group AS a
				LEFT JOIN wiki_artists AS wiki ON wiki.RevisionID = a.RevisionID
			WHERE a.ArtistID = '$ArtistID' ";
	}
	$sql .= "
			GROUP BY a.ArtistID";
	$DB->query($sql);

	if ($DB->record_count() == 0) {
		error(404);
	}

	list($Name, $Image, $Body, $VanityHouseArtist) = $DB->next_record(MYSQLI_NUM, array(0));
}


//----------------- Build list and get stats

ob_start();

// Requests
$Requests = array();
if (empty($LoggedUser['DisableRequests'])) {
	$Requests = $Cache->get_value("artists_requests_$ArtistID");
	if (!is_array($Requests)) {
		$DB->query("
			SELECT
				r.ID,
				r.CategoryID,
				r.Title,
				r.Year,
				r.TimeAdded,
				COUNT(rv.UserID) AS Votes,
				SUM(rv.Bounty) AS Bounty
			FROM requests AS r
				LEFT JOIN requests_votes AS rv ON rv.RequestID = r.ID
				LEFT JOIN requests_artists AS ra ON r.ID = ra.RequestID
			WHERE ra.ArtistID = $ArtistID
				AND r.TorrentID = 0
			GROUP BY r.ID
			ORDER BY Votes DESC");

		if ($DB->record_count() > 0) {
			$Requests = $DB->to_array();
		} else {
			$Requests = array();
		}
		$Cache->cache_value("artists_requests_$ArtistID", $Requests);
	}
}
$NumRequests = count($Requests);


if (($Importances = $Cache->get_value("artist_groups_$ArtistID")) === false) {
	$DB->query("
		SELECT
			DISTINCTROW ta.GroupID, ta.Importance, tg.VanityHouse, tg.Year
		FROM torrents_artists AS ta
			JOIN torrents_group AS tg ON tg.ID = ta.GroupID
		WHERE ta.ArtistID = '$ArtistID'
		ORDER BY tg.Year DESC, tg.Name DESC");
	$GroupIDs = $DB->collect('GroupID');
	$Importances = $DB->to_array(false, MYSQLI_BOTH, false);
	$Cache->cache_value("artist_groups_$ArtistID", $Importances, 0);
} else {
	$GroupIDs = array();
	foreach ($Importances as $Group) {
		$GroupIDs[] = $Group['GroupID'];
	}
}
if (count($GroupIDs) > 0) {
	$TorrentList = Torrents::get_groups($GroupIDs, true, true);
	$TorrentList = $TorrentList['matches'];
} else {
	$TorrentList = array();
}
$NumGroups = count($TorrentList);

if (!empty($TorrentList)) {
?>
<div id="discog_table">
<?
}

//Get list of used release types
$UsedReleases = array();
foreach ($Importances as $ID => $Group) {
	switch ($Importances[$ID]['Importance']) {
		case '2':
			$Importances[$ID]['ReleaseType'] = 1024;
			//$TorrentList[$GroupID]['ReleaseType'] = 1024;
			$GuestAlbums = true;
			break;

		case '3':
			$Importances[$ID]['ReleaseType'] = 1023;
			//$TorrentList[$GroupID]['ReleaseType'] = 1023;
			$RemixerAlbums = true;
			break;

		case '4':
			$Importances[$ID]['ReleaseType'] = 1022;
			//$TorrentList[$GroupID]['ReleaseType'] = 1022;
			$ComposerAlbums = true;
			break;

		case '7':
			$Importances[$ID]['ReleaseType'] = 1021;
			//$TorrentList[$GroupID]['ReleaseType'] = 1021;
			$ProducerAlbums = true;
			break;

		default:
			$Importances[$ID]['ReleaseType'] = $TorrentList[$Group['GroupID']]['ReleaseType'];
	}

	if (!isset($UsedReleases[$Importances[$ID]['ReleaseType']])) {
		$UsedReleases[$Importances[$ID]['ReleaseType']] = true;
	}
	$Importances[$ID]['Sort'] = $ID;
}

if (!empty($GuestAlbums)) {
	$ReleaseTypes[1024] = 'Guest Appearance';
}
if (!empty($RemixerAlbums)) {
	$ReleaseTypes[1023] = 'Remixed By';
}
if (!empty($ComposerAlbums)) {
	$ReleaseTypes[1022] = 'Composition';
}
if (!empty($ProducerAlbums)) {
	$ReleaseTypes[1021] = 'Produced By';
}

//Custom sorting for releases
if (!empty($LoggedUser['SortHide'])) {
	$SortOrder = array_flip(array_keys($LoggedUser['SortHide']));
} else {
	$SortOrder = $ReleaseTypes;
}
// If the $SortOrder array doesn't have all release types, put the missing ones at the end
$MissingTypes = array_diff_key($ReleaseTypes, $SortOrder);
if (!empty($MissingTypes)) {
	$MaxOrder = max($SortOrder);
	foreach (array_keys($MissingTypes) as $Missing) {
		$SortOrder[$Missing] = ++$MaxOrder;
	}
}
uasort($Importances, function ($A, $B) use ($SortOrder) {
	if ($SortOrder[$A['ReleaseType']] == $SortOrder[$B['ReleaseType']]) {
		return (($A['Sort'] < $B['Sort']) ? -1 : 1);
	}
	return (($SortOrder[$A['ReleaseType']] < $SortOrder[$B['ReleaseType']]) ? -1 : 1);
});
// Sort the anchors at the top of the page the same way as release types
$UsedReleases = array_flip(array_intersect_key($SortOrder, $UsedReleases));

reset($TorrentList);
if (!empty($UsedReleases)) { ?>
	<div class="box center">
<?
	foreach ($UsedReleases as $ReleaseID) {
		switch ($ReleaseTypes[$ReleaseID]) {
			case 'Remix':
				$DisplayName = 'Remixes';
				break;
			case 'Anthology':
				$DisplayName = 'Anthologies';
				break;
			case 'DJ Mix':
				$DisplayName = 'DJ Mixes';
				break;
			default:
				$DisplayName = $ReleaseTypes[$ReleaseID].'s';
				break;
		}

		if (!empty($LoggedUser['DiscogView']) || (isset($LoggedUser['SortHide']) && array_key_exists($ReleaseType, $LoggedUser['SortHide']) && $LoggedUser['SortHide'][$ReleaseType] == 1)) {
			$ToggleStr = " onclick=\"$('.releases_$ReleaseID').gshow(); return true;\"";
		} else {
			$ToggleStr = '';
		}
?>
		<a href="#torrents_<?=str_replace(' ', '_', strtolower($ReleaseTypes[$ReleaseID]))?>" class="brackets"<?=$ToggleStr?>><?=$DisplayName?></a>
<?
	}
	if ($NumRequests > 0) {
?>
	<a href="#requests" class="brackets">Requests</a>
<? } ?>
	</div>
<? }

$NumTorrents = 0;
$NumSeeders = 0;
$NumLeechers = 0;
$NumSnatches = 0;

foreach ($TorrentList as $GroupID => $Group) {
	// $Tags array is for the sidebar on the right.
	// Skip compilations and soundtracks.
	$Merge = $Group['ReleaseType'] != 7 && $Group['ReleaseType'] != 3;

	$TorrentTags = new Tags($Group['TagList'], $Merge);

	foreach ($Group['Torrents'] as $TorrentID => $Torrent) {
		$NumTorrents++;

		$Torrent['Seeders'] = (int)$Torrent['Seeders'];
		$Torrent['Leechers'] = (int)$Torrent['Leechers'];
		$Torrent['Snatched'] = (int)$Torrent['Snatched'];

		$NumSeeders += $Torrent['Seeders'];
		$NumLeechers += $Torrent['Leechers'];
		$NumSnatches += $Torrent['Snatched'];
	}
}



$OpenTable = false;
$ShowGroups = !isset($LoggedUser['TorrentGrouping']) || $LoggedUser['TorrentGrouping'] == 0;
$HideTorrents = ($ShowGroups ? '' : ' hidden');
$OldGroupID = 0;
$LastReleaseType = 0;

foreach ($Importances as $Group) {
	extract(Torrents::array_group($TorrentList[$Group['GroupID']]), EXTR_OVERWRITE);
	$ReleaseType = $Group['ReleaseType'];

	if ($GroupID == $OldGroupID && $ReleaseType == $OldReleaseType) {
		continue;
	} else {
		$OldGroupID = $GroupID;
		$OldReleaseType = $ReleaseType;
	}

/* 	if (!empty($LoggedUser['DiscogView']) || (isset($LoggedUser['HideTypes']) && in_array($ReleaseType, $LoggedUser['HideTypes']))) {
		$HideDiscog = ' hidden';
	} else {
		$HideDiscog = '';
	} */
	if (!empty($LoggedUser['DiscogView']) || (isset($LoggedUser['SortHide']) && array_key_exists($ReleaseType, $LoggedUser['SortHide']) && $LoggedUser['SortHide'][$ReleaseType] == 1)) {
		$HideDiscog = ' hidden';
	} else {
		$HideDiscog = '';
	}

	$TorrentTags = new Tags($TagList, false);

	if ($ReleaseType != $LastReleaseType) {
		switch ($ReleaseTypes[$ReleaseType]) {
			case 'Remix':
				$DisplayName = 'Remixes';
				break;
			case 'Anthology':
				$DisplayName = 'Anthologies';
				break;
			case 'DJ Mix':
				$DisplayName = 'DJ Mixes';
				break;
			default:
				$DisplayName = $ReleaseTypes[$ReleaseType].'s';
				break;
		}

		$ReleaseTypeLabel = strtolower(str_replace(' ', '_', $ReleaseTypes[$ReleaseType]));
		if ($OpenTable) { ?>
		</table>
<?		} ?>
			<table class="torrent_table grouped release_table" id="torrents_<?=$ReleaseTypeLabel?>">
				<tr class="colhead_dark">
					<td class="small"><!-- expand/collapse --></td>
					<td width="70%"><a href="#">&uarr;</a>&nbsp;<strong><?=$DisplayName?></strong> (<a href="#" onclick="$('.releases_<?=$ReleaseType?>').gtoggle(true); return false;">View</a>)</td>
					<td>Size</td>
					<td class="sign"><img src="static/styles/<?=$LoggedUser['StyleName'] ?>/images/snatched.png" alt="Snatches" title="Snatches" /></td>
					<td class="sign"><img src="static/styles/<?=$LoggedUser['StyleName'] ?>/images/seeders.png" alt="Seeders" title="Seeders" /></td>
					<td class="sign"><img src="static/styles/<?=$LoggedUser['StyleName'] ?>/images/leechers.png" alt="Leechers" title="Leechers" /></td>
				</tr>
<?		$OpenTable = true;
		$LastReleaseType = $ReleaseType;
	}


	$DisplayName = "<a href=\"torrents.php?id=$GroupID\" title=\"View Torrent\">$GroupName</a>";
	if (check_perms('users_mod') || check_perms('torrents_fix_ghosts')) {
		$DisplayName .= ' <a href="torrents.php?action=fix_group&amp;groupid='.$GroupID.'&amp;artistid='.$ArtistID.'&amp;auth='.$LoggedUser['AuthKey'].'" class="brackets" title="Fix ghost DB entry">Fix</a>';
	}


	switch ($ReleaseType) {
		case 1023: // Remixes, DJ Mixes, Guest artists, and Producers need the artist name
		case 1024:
		case 1021:
		case 8:
			if (!empty($ExtendedArtists[1]) || !empty($ExtendedArtists[4]) || !empty($ExtendedArtists[5]) || !empty($ExtendedArtists[6])) {
				unset($ExtendedArtists[2]);
				unset($ExtendedArtists[3]);
				$DisplayName = Artists::display_artists($ExtendedArtists).$DisplayName;
			} elseif (count($GroupArtists) > 0) {
				$DisplayName = Artists::display_artists(array(1 => $Artists), true, true).$DisplayName;
			}
			break;
		case 1022: // Show performers on composer pages
			if (!empty($ExtendedArtists[1]) || !empty($ExtendedArtists[4]) || !empty($ExtendedArtists[5])) {
				unset($ExtendedArtists[4]);
				unset($ExtendedArtists[3]);
				unset($ExtendedArtists[6]);
				$DisplayName = Artists::display_artists($ExtendedArtists).$DisplayName;
			} elseif (count($GroupArtists) > 0) {
				$DisplayName = Artists::display_artists(array(1 => $Artists), true, true).$DisplayName;
			}
			break;
		default: // Show composers otherwise
			if (!empty($ExtendedArtists[4])) {
				$DisplayName = Artists::display_artists(array(4 => $ExtendedArtists[4]), true, true).$DisplayName;
			}
	}

	if ($GroupYear > 0) {
		$DisplayName = "$GroupYear - $DisplayName";
	}

	if ($GroupVanityHouse) {
		$DisplayName .= ' [<abbr title="This is a Vanity House release">VH</abbr>]';
	}

	$SnatchedGroupClass = ($GroupFlags['IsSnatched'] ? ' snatched_group' : '');
?>
			<tr class="releases_<?=$ReleaseType?> group discog<?=$SnatchedGroupClass . $HideDiscog?>">
				<td class="center">
					<div title="View" id="showimg_<?=$GroupID?>" class="<?=($ShowGroups ? 'hide' : 'show')?>_torrents">
						<a href="#" class="show_torrents_link" onclick="toggle_group(<?=$GroupID?>, this, event)" title="Collapse this group. Hold &quot;Ctrl&quot; while clicking to collapse all groups in this release type."></a>
					</div>
				</td>
				<td colspan="5" class="big_info">
<?	if ($LoggedUser['CoverArt']) : ?>
					<div class="group_image float_left clear">
						<? ImageTools::cover_thumb($WikiImage, $GroupCategoryID) ?>
					</div>
<?	endif; ?>
					<div class="group_info clear">
						<strong><?=$DisplayName?></strong>
						<? if (Bookmarks::has_bookmarked('torrent', $GroupID)) {
							echo "<a style=\"float: right;\" href=\"#\" id=\"bookmarklink_torrent_$GroupID\" class=\"remove_bookmark brackets\" title=\"Unbookmark\" onclick=\"Unbookmark('torrent', $GroupID, 'Bookmark'); return false;\">Unbookmark</a>";
						} else {
							echo "<a style=\"float: right;\" href=\"#\" id=\"bookmarklink_torrent_$GroupID\" class=\"add_bookmark brackets\" title=\"Bookmark\" onclick=\"Bookmark('torrent', $GroupID, 'Unbookmark'); return false;\">Bookmark</a>";
						} ?>
						<?Votes::vote_link($GroupID, $UserVotes[$GroupID]['Type']);?>
						<div class="tags"><?=$TorrentTags->format('torrents.php?taglist=', $Name)?></div>
					</div>
				</td>
			</tr>
<?
	$LastRemasterYear = '-';
	$LastRemasterTitle = '';
	$LastRemasterRecordLabel = '';
	$LastRemasterCatalogueNumber = '';
	$LastMedia = '';

	$EditionID = 0;
	unset($FirstUnknown);

	foreach ($Torrents as $TorrentID => $Torrent) {
		if ($Torrent['Remastered'] && !$Torrent['RemasterYear']) {
			$FirstUnknown = !isset($FirstUnknown);
		}
		$SnatchedTorrentClass = ($Torrent['IsSnatched'] ? ' snatched_torrent' : '');

		if ($Torrent['RemasterTitle'] != $LastRemasterTitle || $Torrent['RemasterYear'] != $LastRemasterYear ||
			$Torrent['RemasterRecordLabel'] != $LastRemasterRecordLabel || $Torrent['RemasterCatalogueNumber'] !=
			$LastRemasterCatalogueNumber || $FirstUnknown || $Torrent['Media'] != $LastMedia
			) {

			$EditionID++;

?>
	<tr class="releases_<?=$ReleaseType?> groupid_<?=$GroupID?> edition group_torrent discog<?=$SnatchedGroupClass . $HideDiscog . $HideTorrents?>">
		<td colspan="6" class="edition_info"><strong><a href="#" onclick="toggle_edition(<?=$GroupID?>, <?=$EditionID?>, this, event)" title="Collapse this edition. Hold &quot;Ctrl&quot; while clicking to collapse all editions in this torrent group.">&minus;</a> <?=Torrents::edition_string($Torrent, $TorrentList[$Group['GroupID']])?></strong></td>
	</tr>
<?
		}
		$LastRemasterTitle = $Torrent['RemasterTitle'];
		$LastRemasterYear = $Torrent['RemasterYear'];
		$LastRemasterRecordLabel = $Torrent['RemasterRecordLabel'];
		$LastRemasterCatalogueNumber = $Torrent['RemasterCatalogueNumber'];
		$LastMedia = $Torrent['Media'];
?>
	<tr class="releases_<?=$ReleaseType?> torrent_row groupid_<?=$GroupID?> edition_<?=$EditionID?> group_torrent discog<?=$SnatchedTorrentClass . $SnatchedGroupClass . $HideDiscog . $HideTorrents?>">
		<td colspan="2">
			<span>
				[ <a href="torrents.php?action=download&amp;id=<?=$TorrentID?>&amp;authkey=<?=$LoggedUser['AuthKey']?>&amp;torrent_pass=<?=$LoggedUser['torrent_pass']?>" title="Download"><?=$Torrent['HasFile'] ? 'DL' : 'Missing'?></a>
<?		if (Torrents::can_use_token($Torrent)) { ?>
						| <a href="torrents.php?action=download&amp;id=<?=$TorrentID ?>&amp;authkey=<?=$LoggedUser['AuthKey']?>&amp;torrent_pass=<?=$LoggedUser['torrent_pass']?>&amp;usetoken=1" title="Use a FL Token" onclick="return confirm('Are you sure you want to use a freeleech token here?');">FL</a>
<?		} ?> ]
			</span>
			&nbsp;&nbsp;&raquo;&nbsp; <a href="torrents.php?id=<?=$GroupID?>&amp;torrentid=<?=$TorrentID?>"><?=Torrents::torrent_info($Torrent)?></a>
		</td>
		<td class="nobr"><?=Format::get_size($Torrent['Size'])?></td>
		<td><?=number_format($Torrent['Snatched'])?></td>
		<td<?=(($Torrent['Seeders'] == 0) ? ' class="r00"' : '')?>><?=number_format($Torrent['Seeders'])?></td>
		<td><?=number_format($Torrent['Leechers'])?></td>
	</tr>
<?
	}
}
if (!empty($TorrentList)) { ?>
			</table>
		</div>
<?
}

$TorrentDisplayList = ob_get_clean();

//----------------- End building list and getting stats

View::show_header($Name, 'browse,requests,bbcode,comments,voting,recommend');
?>
<div class="thin">
	<div class="header">
		<h2><?=display_str($Name)?><? if ($RevisionID) { ?> (Revision #<?=$RevisionID?>)<? } if ($VanityHouseArtist) { ?> [Vanity House] <? } ?></h2>
		<div class="linkbox">
<?	if (check_perms('site_submit_requests')) { ?>
			<a href="requests.php?action=new&amp;artistid=<?=$ArtistID?>" class="brackets">Add request</a>
<?
	}

if (check_perms('site_torrents_notify')) {
	if (($Notify = $Cache->get_value('notify_artists_'.$LoggedUser['ID'])) === false) {
		$DB->query("
			SELECT ID, Artists
			FROM users_notify_filters
			WHERE UserID = '$LoggedUser[ID]'
				AND Label = 'Artist notifications'
			LIMIT 1");
		$Notify = $DB->next_record(MYSQLI_ASSOC, false);
		$Cache->cache_value('notify_artists_'.$LoggedUser['ID'], $Notify, 0);
	}
	if (stripos($Notify['Artists'], '|'.$Name.'|') === false) {
?>
			<a href="artist.php?action=notify&amp;artistid=<?=$ArtistID?>&amp;auth=<?=$LoggedUser['AuthKey']?>" class="brackets">Notify of new uploads</a>
<?	} else { ?>
			<a href="artist.php?action=notifyremove&amp;artistid=<?=$ArtistID?>&amp;auth=<?=$LoggedUser['AuthKey']?>" class="brackets">Do not notify of new uploads</a>
<?
	}
}

	if (Bookmarks::has_bookmarked('artist', $ArtistID)) {
?>
			<a href="#" id="bookmarklink_artist_<?=$ArtistID?>" onclick="Unbookmark('artist', <?=$ArtistID?>, 'Bookmark'); return false;" class="brackets">Remove bookmark</a>
<?	} else { ?>
			<a href="#" id="bookmarklink_artist_<?=$ArtistID?>" onclick="Bookmark('artist', <?=$ArtistID?>, 'Remove bookmark'); return false;" class="brackets">Bookmark</a>
<?	} ?>
<!--	<a href="#" id="recommend" class="brackets">Recommend</a> -->
<?
	if (check_perms('site_edit_wiki')) {
?>
			<a href="artist.php?action=edit&amp;artistid=<?=$ArtistID?>" class="brackets">Edit</a>
<?	} ?>
			<a href="artist.php?action=history&amp;artistid=<?=$ArtistID?>" class="brackets">View history</a>
<?	if ($RevisionID && check_perms('site_edit_wiki')) { ?>
			<a href="artist.php?action=revert&amp;artistid=<?=$ArtistID?>&amp;revisionid=<?=$RevisionID?>&amp;auth=<?=$LoggedUser['AuthKey']?>" class="brackets">Revert to this revision</a>
<?	} ?>
			<a href="artist.php?id=<?=$ArtistID?>#info" class="brackets">Info</a>
<?	if (defined('LASTFM_API_KEY')) { ?>
			<a href="artist.php?id=<?=$ArtistID?>#concerts" class="brackets">Concerts</a>
<?	} ?>
			<a href="artist.php?id=<?=$ArtistID?>#artistcomments" class="brackets">Comments</a>
<?	if (check_perms('site_delete_artist') && check_perms('torrents_delete')) { ?>
			<a href="artist.php?action=delete&amp;artistid=<?=$ArtistID?>&amp;auth=<?=$LoggedUser['AuthKey']?>" class="brackets">Delete</a>
<?	} ?>
		</div>
	</div>
<? /* Misc::display_recommend($ArtistID, "artist"); */ ?>
	<div class="sidebar">
<?	if ($Image) { ?>
		<div class="box box_image">
			<div class="head"><strong><?=$Name?></strong></div>
			<div style="text-align: center; padding: 10px 0px;">
				<img style="max-width: 220px;" src="<?=ImageTools::process($Image, true)?>" alt="<?=$Name?>" onclick="lightbox.init('<?=ImageTools::process($Image)?>', 220);" />
			</div>
		</div>
<?	} ?>

		<div class="box box_search">
			<div class="head"><strong>Search file lists</strong></div>
			<ul class="nobullet">
				<li>
					<form class="search_form" name="filelists" action="torrents.php">
						<input type="hidden" name="artistname" value="<?=$Name?>" />
						<input type="hidden" name="action" value="advanced" />
						<input type="text" autocomplete="off" id="filelist" name="filelist" size="20" />
						<input type="submit" value="&gt;" />
					</form>
				</li>
			</ul>
		</div>
<?

if (check_perms('zip_downloader')) {
	if (isset($LoggedUser['Collector'])) {
		list($ZIPList,$ZIPPrefs) = $LoggedUser['Collector'];
		$ZIPList = explode(':',$ZIPList);
	} else {
		$ZIPList = array('00','11');
		$ZIPPrefs = 1;
	}
?>
		<div class="box box_zipdownload">
			<div class="head colhead_dark"><strong>Collector</strong></div>
			<div class="pad">
				<form class="download_form" name="zip" action="artist.php" method="post">
					<input type="hidden" name="action" value="download" />
					<input type="hidden" name="auth" value="<?=$LoggedUser['AuthKey']?>" />
					<input type="hidden" name="artistid" value="<?=$ArtistID?>" />
					<ul id="list" class="nobullet">
<? foreach ($ZIPList as $ListItem) { ?>
						<li id="list<?=$ListItem?>">
							<input type="hidden" name="list[]" value="<?=$ListItem?>" />
							<span style="float: left;"><?=$ZIPOptions[$ListItem]['2']?></span>
							<span class="remove remove_collector"><a href="#" onclick="remove_selection('<?=$ListItem?>');return false;" style="float: right;" class="brackets" title="Remove format from the Collector">X</a></span>
							<br style="clear: all;" />
						</li>
<? } ?>
					</ul>
					<select id="formats" style="width: 180px;">
<?
$OpenGroup = false;
$LastGroupID = -1;

foreach ($ZIPOptions as $Option) {
	list($GroupID,$OptionID,$OptName) = $Option;

	if ($GroupID != $LastGroupID) {
		$LastGroupID = $GroupID;
		if ($OpenGroup) { ?>
						</optgroup>
<?		} ?>
						<optgroup label="<?=$ZIPGroups[$GroupID]?>">
<?		$OpenGroup = true;
	}
?>
							<option id="opt<?=$GroupID.$OptionID?>" value="<?=$GroupID.$OptionID?>"<? if (in_array($GroupID.$OptionID, $ZIPList)) { echo ' disabled="disabled"'; } ?>><?=$OptName?></option>
<?
}
?>
						</optgroup>
					</select>
					<button type="button" onclick="add_selection()">+</button>
					<select name="preference" style="width: 210px;">
						<option value="0"<? if ($ZIPPrefs == 0) { echo ' selected="selected"'; } ?>>Prefer Original</option>
						<option value="1"<? if ($ZIPPrefs == 1) { echo ' selected="selected"'; } ?>>Prefer Best Seeded</option>
						<option value="2"<? if ($ZIPPrefs == 2) { echo ' selected="selected"'; } ?>>Prefer Bonus Tracks</option>
					</select>
					<input type="submit" style="width: 210px;" value="Download" />
				</form>
			</div>
		</div>
<? } ?>
		<div class="box box_tags">
			<div class="head"><strong>Tags</strong></div>
			<ul class="stats nobullet">
<?
			Tags::format_top(50, "torrents.php?taglist=", $Name);
?>
			</ul>
		</div>
<?

// Stats
?>
		<div class="box box_info box_statistics_artist">
			<div class="head"><strong>Statistics</strong></div>
			<ul class="stats nobullet">
				<li>Number of groups: <?=number_format($NumGroups)?></li>
				<li>Number of torrents: <?=number_format($NumTorrents)?></li>
				<li>Number of seeders: <?=number_format($NumSeeders)?></li>
				<li>Number of leechers: <?=number_format($NumLeechers)?></li>
				<li>Number of snatches: <?=number_format($NumSnatches)?></li>
			</ul>
		</div>
<?


if (empty($SimilarArray)) {
	$DB->query("
		SELECT
			s2.ArtistID,
			a.Name,
			ass.Score,
			ass.SimilarID
		FROM artists_similar AS s1
			JOIN artists_similar AS s2 ON s1.SimilarID=s2.SimilarID AND s1.ArtistID!=s2.ArtistID
			JOIN artists_similar_scores AS ass ON ass.SimilarID=s1.SimilarID
			JOIN artists_group AS a ON a.ArtistID=s2.ArtistID
		WHERE s1.ArtistID='$ArtistID'
		ORDER BY ass.Score DESC
		LIMIT 30
	");
	$SimilarArray = $DB->to_array();
	$NumSimilar = count($SimilarArray);
}
?>
		<div class="box box_artists">
			<div class="head"><strong>Similar artists</strong></div>
			<ul class="stats nobullet">
<?
	if ($NumSimilar == 0) { ?>
				<li><span style="font-style: italic;">None found</span></li>
<?	}
	$First = true;
	foreach ($SimilarArray as $SimilarArtist) {
		list($Artist2ID, $Artist2Name, $Score, $SimilarID) = $SimilarArtist;
		$Score = $Score / 100;
		if ($First) {
			$Max = $Score + 1;
			$First = false;
		}

		$FontSize = (ceil((((($Score - 2) / $Max - 2) * 4)))) + 8;

?>
				<li>
					<span title="<?=$Score?>"><a href="artist.php?id=<?=$Artist2ID?>" style="float: left; display: block;"><?=$Artist2Name?></a></span>
					<div style="float: right; display: block; letter-spacing: -1px;">
						<a href="artist.php?action=vote_similar&amp;artistid=<?=$ArtistID?>&amp;similarid=<?=$SimilarID?>&amp;way=down" style="font-family: monospace;" title="Vote down this similar artist. Use this when you feel that the two artists are not all that similar." class="brackets">&minus;</a>
						<a href="artist.php?action=vote_similar&amp;artistid=<?=$ArtistID?>&amp;similarid=<?=$SimilarID?>&amp;way=up" style="font-family: monospace;" title="Vote up this similar artist. Use this when you feel that the two artists are quite similar." class="brackets">+</a>
<?		if (check_perms('site_delete_tag')) { ?>
						<span class="remove remove_artist"><a href="artist.php?action=delete_similar&amp;similarid=<?=$SimilarID?>&amp;auth=<?=$LoggedUser['AuthKey']?>" title="Remove this similar artist" class="brackets">X</a></span>
<?		} ?>
					</div>
					<br style="clear: both;" />
				</li>
<?		} ?>
			</ul>
		</div>
		<div class="box box_addartists box_addartists_similar">
			<div class="head"><strong>Add similar artist</strong></div>
			<ul class="nobullet">
				<li>
					<form class="add_form" name="similar_artists" action="artist.php" method="post">
						<input type="hidden" name="action" value="add_similar" />
						<input type="hidden" name="auth" value="<?=$LoggedUser['AuthKey']?>" />
						<input type="hidden" name="artistid" value="<?=$ArtistID?>" />
						<input type="text" autocomplete="off" id="artistsimilar" name="artistname" size="20"<? Users::has_autocomplete_enabled('other'); ?> />
						<input type="submit" value="+" />
					</form>
				</li>
			</ul>
		</div>
	</div>
	<div class="main_column">
<?

echo $TorrentDisplayList;

$Collages = $Cache->get_value("artists_collages_$ArtistID");
if (!is_array($Collages)) {
	$DB->query("
		SELECT c.Name, c.NumTorrents, c.ID
		FROM collages AS c
			JOIN collages_artists AS ca ON ca.CollageID = c.ID
		WHERE ca.ArtistID = '$ArtistID'
			AND Deleted = '0'
			AND CategoryID = '7'");
	$Collages = $DB->to_array();
	$Cache->cache_value("artists_collages_$ArtistID", $Collages, 3600 * 6);
}
if (count($Collages) > 0) {
	if (count($Collages) > MAX_COLLAGES) {
		// Pick some at random
		$Range = range(0,count($Collages) - 1);
		shuffle($Range);
		$Indices = array_slice($Range, 0, MAX_COLLAGES);
		$SeeAll = ' <a href="#" onclick="$(\'.collage_rows\').gtoggle(); return false;">(See all)</a>';
	} else {
		$Indices = range(0, count($Collages)-1);
		$SeeAll = '';
	}
?>
	<table class="collage_table" id="collages">
		<tr class="colhead">
			<td width="85%"><a href="#">&uarr;</a>&nbsp;This artist is in <?=number_format(count($Collages))?> collage<?=((count($Collages) > 1) ? 's' : '')?><?=$SeeAll?></td>
			<td># artists</td>
		</tr>
		<?	foreach ($Indices as $i) {
				list($CollageName, $CollageArtists, $CollageID) = $Collages[$i];
				unset($Collages[$i]);
		?>
					<tr>
						<td><a href="collages.php?id=<?=$CollageID?>"><?=$CollageName?></a></td>
						<td><?=number_format($CollageArtists)?></td>
					</tr>
		<?	}
			foreach ($Collages as $Collage) {
				list($CollageName, $CollageArtists, $CollageID) = $Collage;
		?>
					<tr class="collage_rows hidden">
						<td><a href="collages.php?id=<?=$CollageID?>"><?=$CollageName?></a></td>
						<td><?=number_format($CollageArtists)?></td>
					</tr>
		<?	} ?>
	</table>
<?
}

if ($NumRequests > 0) {

?>
	<table cellpadding="6" cellspacing="1" border="0" class="request_table border" width="100%" id="requests">
		<tr class="colhead_dark">
			<td style="width: 48%;">
				<a href="#">&uarr;</a>&nbsp;
				<strong>Request name</strong>
			</td>
			<td>
				<strong>Vote</strong>
			</td>
			<td>
				<strong>Bounty</strong>
			</td>
			<td>
				<strong>Added</strong>
			</td>
		</tr>
<?
	foreach ($Requests as $Request) {
		list($RequestID, $CategoryID, $Title, $Year, $TimeAdded, $Votes, $Bounty) = $Request;

			$CategoryName = $Categories[$CategoryID - 1];

			if ($CategoryName == 'Music') {
				$ArtistForm = Requests::get_artists($RequestID);
				$ArtistLink = Artists::display_artists($ArtistForm, true, true);
				$FullName = $ArtistLink."<a href=\"requests.php?action=view&amp;id=$RequestID\">$Title [$Year]</a>";
			} elseif ($CategoryName == 'Audiobooks' || $CategoryName == 'Comedy') {
				$FullName = "<a href=\"requests.php?action=view&amp;id=$RequestID\">$Title [$Year]</a>";
			} else {
				$FullName ="<a href=\"requests.php?action=view&amp;id=$RequestID\">$Title</a>";
			}

			$Row = ($Row == 'a') ? 'b' : 'a';

			$Tags = Requests::get_tags($RequestID);
			$ReqTagList = array();
			foreach ($Tags as $TagID => $TagName) {
				$ReqTagList[] = "<a href=\"requests.php?tags=$TagName\">".display_str($TagName).'</a>';
			}
			$ReqTagList = implode(', ', $ReqTagList);
?>
		<tr class="row<?=$Row?>">
			<td>
				<?=$FullName?>
				<div class="tags"><?=$ReqTagList?></div>
			</td>
			<td>
				<span id="vote_count_<?=$RequestID?>"><?=$Votes?></span>
<?		if (check_perms('site_vote')) { ?>
				<input type="hidden" id="auth" name="auth" value="<?=$LoggedUser['AuthKey']?>" />
				&nbsp;&nbsp; <a href="javascript:Vote(0, <?=$RequestID?>)" class="brackets"><strong>+</strong></a>
<?		} ?>
			</td>
			<td>
				<span id="bounty_<?=$RequestID?>"><?=Format::get_size($Bounty)?></span>
			</td>
			<td>
				<?=time_diff($TimeAdded)?>
			</td>
		</tr>
<?	} ?>
	</table>
<?
}

// Similar artist map

if ($NumSimilar > 0) {
	if ($SimilarData = $Cache->get_value("similar_positions_$ArtistID")) {
		$Similar = new ARTISTS_SIMILAR($ArtistID, $Name);
		$Similar->load_data($SimilarData);
		if (!(current($Similar->Artists)->NameLength)) {
			unset($Similar);
		}
	}
	if (empty($Similar) || empty($Similar->Artists)) {
		include(SERVER_ROOT.'/classes/image.class.php');
		$Img = new IMAGE;
		$Img->create(WIDTH, HEIGHT);
		$Img->color(255, 255, 255, 127);

		$Similar = new ARTISTS_SIMILAR($ArtistID, $Name);
		$Similar->set_up();
		$Similar->set_positions();
		$Similar->background_image();

		$SimilarData = $Similar->dump_data();

		$Cache->cache_value("similar_positions_$ArtistID", $SimilarData, 3600 * 24);
	}
?>
		<div id="similar_artist_map" class="box">
			<div id="flipper_head" class="head">
				<a href="#">&uarr;</a>&nbsp;
				<strong id="flipper_title">Similar artist map</strong>
				<a id="flip_to" class="brackets" href="#" onclick="flipView(); return false;">Switch to cloud</a>
			</div>
			<div id="flip_view_1" style="display: block; width: <?=(WIDTH)?>px; height: <?=(HEIGHT)?>px; position: relative; background-image: url(static/similar/<?=($ArtistID)?>.png?t=<?=(time())?>);">
<?
	$Similar->write_artists();
?>
			</div>
		<div id="flip_view_2" style="display: none; width: <?=WIDTH?>px; height: <?=HEIGHT?>px;">
			<canvas width="<?=(WIDTH)?>px" height="<?=(HEIGHT - 20)?>px" id="similarArtistsCanvas"></canvas>
			<div id="artistTags" style="display: none;">
				<ul><li></li></ul>
			</div>
			<strong style="margin-left: 10px;"><a id="currentArtist" href="#null">Loading...</a></strong>
		</div>
		</div>

<script type="text/javascript">//<![CDATA[
var cloudLoaded = false;

function flipView() {
	var state = document.getElementById('flip_view_1').style.display == 'block';

	if (state) {
		document.getElementById('flip_view_1').style.display = 'none';
		document.getElementById('flip_view_2').style.display = 'block';
		document.getElementById('flipper_title').innerHTML = 'Similar artist cloud';
		document.getElementById('flip_to').innerHTML = 'Switch to map';

		if (!cloudLoaded) {
			require("static/functions/tagcanvas.js", function () {
				require("static/functions/artist_cloud.js", function () {
				});
			});
			cloudLoaded = true;
		}
	}
	else {
		document.getElementById('flip_view_1').style.display = 'block';
		document.getElementById('flip_view_2').style.display = 'none';
		document.getElementById('flipper_title').innerHTML = 'Similar artist map';
		document.getElementById('flip_to').innerHTML = 'Switch to cloud';
	}
}

//TODO move this to global, perhaps it will be used elsewhere in the future
//http://stackoverflow.com/questions/7293344/load-javascript-dynamically
function require(file, callback) {
	var script = document.getElementsByTagName('script')[0],
	newjs = document.createElement('script');

	// IE
	newjs.onreadystatechange = function () {
		if (newjs.readyState === 'loaded' || newjs.readyState === 'complete') {
			newjs.onreadystatechange = null;
			callback();
		}
	};
	// others
	newjs.onload = function () {
		callback();
	};
	newjs.src = file;
	script.parentNode.insertBefore(newjs, script);
}
//]]>
</script>

<? } // if $NumSimilar > 0 ?>
		<div class="box">
			<div id="info" class="head">
				<a href="#">&uarr;</a>&nbsp;
				<strong>Artist info</strong>
				<a href="#" class="brackets" onclick="$('#body').gtoggle(); return false;">Toggle</a>
			</div>
			<div id="body" class="body"><?=$Text->full_format($Body)?></div>
		</div>
<?	if (defined('LASTFM_API_KEY')) {
		include(SERVER_ROOT.'/sections/artist/concerts.php');
	} ?>
<?php
// --- Comments ---

// gets the amount of comments for this group
$Results = $Cache->get_value("artist_comments_$ArtistID");
if ($Results === false) {
	$DB->query("
		SELECT
			COUNT(c.ID)
		FROM artist_comments as c
		WHERE c.ArtistID = '$ArtistID'");
	list($Results) = $DB->next_record();
	$Cache->cache_value("artist_comments_$ArtistID", $Results, 0);
}

if (isset($_GET['postid']) && is_number($_GET['postid']) && $Results > TORRENT_COMMENTS_PER_PAGE) {
	$DB->query("
		SELECT COUNT(ID)
		FROM artist_comments
		WHERE ArtistID = $ArtistID
			AND ID <= $_GET[postid]");
	list($PostNum) = $DB->next_record();
	list($Page, $Limit) = Format::page_limit(TORRENT_COMMENTS_PER_PAGE, $PostNum);
} else {
	list($Page, $Limit) = Format::page_limit(TORRENT_COMMENTS_PER_PAGE, $Results);
}

//Get the cache catalogue
$CatalogueID = floor((TORRENT_COMMENTS_PER_PAGE * $Page - TORRENT_COMMENTS_PER_PAGE) / THREAD_CATALOGUE);
$CatalogueLimit = $CatalogueID * THREAD_CATALOGUE . ', ' . THREAD_CATALOGUE;

//---------- Get some data to start processing

// Cache catalogue from which the page is selected, allows block caches and future ability to specify posts per page
$Catalogue = $Cache->get_value("artist_comments_{$ArtistID}_catalogue_$CatalogueID");
if ($Catalogue === false) {
	$DB->query("
		SELECT
			c.ID,
			c.AuthorID,
			c.AddedTime,
			c.Body,
			c.EditedUserID,
			c.EditedTime,
			u.Username
		FROM artist_comments as c
			LEFT JOIN users_main AS u ON u.ID = c.EditedUserID
		WHERE c.ArtistID = '$ArtistID'
		ORDER BY c.ID
		LIMIT $CatalogueLimit");
	$Catalogue = $DB->to_array(false, MYSQLI_ASSOC);
	$Cache->cache_value("artist_comments_{$ArtistID}_catalogue_$CatalogueID", $Catalogue, 0);
}

//This is a hybrid to reduce the catalogue down to the page elements: We use the page limit % catalogue
$Thread = array_slice($Catalogue, ((TORRENT_COMMENTS_PER_PAGE * $Page - TORRENT_COMMENTS_PER_PAGE) % THREAD_CATALOGUE), TORRENT_COMMENTS_PER_PAGE, true);
?>
	<div id="artistcomments" class="linkbox">
		<a name="comments"></a>
<?
$Pages = Format::get_pages($Page, $Results, TORRENT_COMMENTS_PER_PAGE, 9, '#comments');
echo $Pages;
?>
	</div>
<?

//---------- Begin printing
foreach ($Thread as $Key => $Post) {
	list($PostID, $AuthorID, $AddedTime, $CommentBody, $EditedUserID, $EditedTime, $EditedUsername) = array_values($Post);
	list($AuthorID, $Username, $PermissionID, $Paranoia, $Artist, $Donor, $Warned, $Avatar, $Enabled, $UserTitle) = array_values(Users::user_info($AuthorID));
?>
<table class="forum_post box vertical_margin<?=(!Users::has_avatars_enabled() ? ' noavatar' : '')?>" id="post<?=$PostID?>">
	<colgroup>
<?	if (Users::has_avatars_enabled()) { ?>
		<col class="col_avatar" />
<? 	} ?>
		<col class="col_post_body" />
	</colgroup>
	<tr class="colhead_dark">
		<td colspan="<?=(Users::has_avatars_enabled() ? 2 : 1)?>">
			<div style="float: left;"><a class="post_id" href="artist.php?id=<?=$ArtistID?>&amp;postid=<?=$PostID?>#post<?=$PostID?>">#<?=$PostID?></a>
				<strong><?=Users::format_username($AuthorID, true, true, true, true)?></strong> <?=time_diff($AddedTime)?>
				- <a href="#quickpost" onclick="Quote('<?=$PostID?>','<?=$Username?>');" class="brackets">Quote</a>
<?	if ($AuthorID == $LoggedUser['ID'] || check_perms('site_moderate_forums')) { ?>
				- <a href="#post<?=$PostID?>" onclick="Edit_Form('<?=$PostID?>','<?=$Key?>');" class="brackets">Edit</a>
<?
	}
	if (check_perms('site_moderate_forums')) { ?>
				- <a href="#post<?=$PostID?>" onclick="Delete('<?=$PostID?>');" class="brackets">Delete</a>
<?	} ?>
			</div>
			<div id="bar<?=$PostID?>" style="float: right;">
				<a href="reports.php?action=report&amp;type=artist_comment&amp;id=<?=$PostID?>" class="brackets">Report</a>
<?
	if (check_perms('users_warn') && $AuthorID != $LoggedUser['ID']) {
		$AuthorInfo = Users::user_info($AuthorID);
		if ($LoggedUser['Class'] >= $AuthorInfo['Class']) {
?>
				<form class="manage_form hidden" name="user" id="warn<?=$PostID?>" action="" method="post">
					<input type="hidden" name="action" value="warn" />
					<input type="hidden" name="artistid" value="<?=$ArtistID?>" />
					<input type="hidden" name="postid" value="<?=$PostID?>" />
					<input type="hidden" name="userid" value="<?=$AuthorID?>" />
					<input type="hidden" name="key" value="<?=$Key?>" />
				</form>
				- <a href="#" onclick="$('#warn<?=$PostID?>').raw().submit(); return false;" class="brackets">Warn</a>
<?
		}
	}
?>
				&nbsp;
				<a href="#">&uarr;</a>
			</div>
		</td>
	</tr>
	<tr>
<?	if (Users::has_avatars_enabled()) { ?>
		<td class="avatar" valign="top">
		<?=Users::show_avatar($Avatar, $Username, $HeavyInfo['DisableAvatars'])?>
		</td>
<?	} ?>
		<td class="body" valign="top">
			<div id="content<?=$PostID?>">
<?=$Text->full_format($CommentBody)?>
<?	if ($EditedUserID) { ?>
				<br />
				<br />
<?		if (check_perms('site_admin_forums')) { ?>
				<a href="#content<?=$PostID?>" onclick="LoadEdit('artist', <?=$PostID?>, 1); return false;">&laquo;</a>
<? 		} ?>
				Last edited by
				<?=Users::format_username($EditedUserID, false, false, false) ?> <?=time_diff($EditedTime, 2, true, true)?>
<?	} ?>
			</div>
		</td>
	</tr>
</table>
<? } ?>
		<div class="linkbox">
		<?=($Pages)?>
		</div>
<?
	View::parse('generic/reply/quickreply.php', array(
			'InputName' => 'artistid',
			'InputID' => $ArtistID));
?>
	</div>
</div>
<?
View::show_footer();


// Cache page for later use

if ($RevisionID) {
	$Key = "artist_$ArtistID" . "_revision_$RevisionID";
} else {
	$Key = 'artist_' . $ArtistID;
}

$Data = array(array($Name, $Image, $Body, $NumSimilar, $SimilarArray, array(), array(), $VanityHouseArtist));

$Cache->cache_value($Key, $Data, 3600);
?>
