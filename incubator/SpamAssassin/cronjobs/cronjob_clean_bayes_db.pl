#!/usr/bin/perl

# i-MSCP - internet Multi Server Control Panel
# Copyright (C) 2010-2014 by internet Multi Server Control Panel
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
#
# @category    iMSCP
# @package     iMSCP_Plugin
# @subpackage  SpamAssassin
# @copyright   Sascha Bay <info@space2place.de>
# @copyright   Rene Schuster <mail@reneschuster.de>
# @author      Sascha Bay <info@space2place.de>
# @author      Rene Schuster <mail@reneschuster.de>
# @link        http://i-mscp.net i-MSCP Home Site
# @license     http://www.gnu.org/licenses/gpl-2.0.html GPL v2

use strict;
use warnings;

use lib "{IMSCP_PERLLIB_PATH}";

use iMSCP::Debug;
use iMSCP::Bootstrapper;

$ENV{'LC_MESSAGES'} = 'C';
$ENV{'IMSCP_CLEAR_SCREEN'} = 0;

umask(027);

newDebug('spamassassin-plugin-cronjob-clean-bayes-db.log');

silent(1);

iMSCP::Bootstrapper->getInstance()->boot(
	{
		'norequirements' => 'yes',
		'config_readonly' => 'yes',
		'nolock' => 'yes'
	}
);

my $pluginFile = "$main::imscpConfig{'GUI_ROOT_DIR'}/plugins/SpamAssassin/backend/SpamAssassin.pm";
my $rs = 0;

eval { require $pluginFile; };

if($@) {
	error($@);
	$rs = 1;
} else {
	my $pluginClass = "Plugin::SpamAssassin";
	$rs = $pluginClass->getInstance()->cleanBayesDb();
}

exit $rs;
