<div class="container">
<br />
<div class="card">
	<div class="card-header">
		<?= __("QSL Statistics"); ?>
	</div>
	<?php
	if ($qsoarray) {
		$modeTotals = [];
		foreach ($qsoarray as $mode => $bandData) {
			foreach ($bandData as $band => $stats) {
				if (!isset($modeTotals[$mode])) {
					$modeTotals[$mode] = ['qso'=>0,'qsl'=>0,'lotw'=>0,'eqsl'=>0,'qrz'=>0,'clublog'=>0];
				}
				$modeTotals[$mode]['qso']     += $stats['qso']     ?? 0;
				$modeTotals[$mode]['qsl']     += $stats['qsl']     ?? 0;
				$modeTotals[$mode]['lotw']    += $stats['lotw']    ?? 0;
				$modeTotals[$mode]['eqsl']    += $stats['eqsl']    ?? 0;
				$modeTotals[$mode]['qrz']     += $stats['qrz']     ?? 0;
				$modeTotals[$mode]['clublog'] += $stats['clublog'] ?? 0;
			}
		}
		if ($qsosatarray) {
			foreach ($qsosatarray as $mode => $satData) {
				foreach ($satData as $sat => $stats) {
					if (!isset($modeTotals[$mode])) {
						$modeTotals[$mode] = ['qso'=>0,'qsl'=>0,'lotw'=>0,'eqsl'=>0,'qrz'=>0,'clublog'=>0];
					}
					$modeTotals[$mode]['qso']     += $stats['qso']     ?? 0;
					$modeTotals[$mode]['qsl']     += $stats['qsl']     ?? 0;
					$modeTotals[$mode]['lotw']    += $stats['lotw']    ?? 0;
					$modeTotals[$mode]['eqsl']    += $stats['eqsl']    ?? 0;
					$modeTotals[$mode]['qrz']     += $stats['qrz']     ?? 0;
					$modeTotals[$mode]['clublog'] += $stats['clublog'] ?? 0;
				}
			}
		}

		$grandQso = $grandQsl = $grandLotw = $grandEqsl = $grandQrz = $grandClublog = 0;

		echo '
		<div class="mx-2"><div class="table-wrapper" style="width:100%">
			<table style="width: 100%" class="flex-wrap table-sm table table-bordered table-hover table-striped table-condensed text-center">
				<thead>
					<tr><th colspan="7">' . __("Overall Stats by Mode") . '</th></tr>
				</thead>
				<tbody>
					<tr>
						<th></th>
						<th>QSO</th>
						<th>QSL</th>
						<th>LoTW</th>
						<th>eQSL</th>
						<th>QRZ</th>
						<th>Clublog</th>
					</tr>';

		foreach ($modeTotals as $mode => $totals) {
			if (($totals['qso'] + $totals['qsl'] + $totals['lotw'] + $totals['eqsl'] + $totals['qrz'] + $totals['clublog']) > 0) {
				$grandQso     += $totals['qso'];
				$grandQsl     += $totals['qsl'];
				$grandLotw    += $totals['lotw'];
				$grandEqsl    += $totals['eqsl'];
				$grandQrz     += $totals['qrz'];
				$grandClublog += $totals['clublog'];
				echo '<tr>
					<th>' . $mode . '</th>
					<td>' . $totals['qso']     . '</td>
					<td>' . $totals['qsl']     . '</td>
					<td>' . $totals['lotw']    . '</td>
					<td>' . $totals['eqsl']    . '</td>
					<td>' . $totals['qrz']     . '</td>
					<td>' . $totals['clublog'] . '</td>
				</tr>';
			}
		}

		echo '</tbody>
			<tfoot>
				<tr>
					<th>' . __("Total") . '</th>
					<th>' . $grandQso     . '</th>
					<th>' . $grandQsl     . '</th>
					<th>' . $grandLotw    . '</th>
					<th>' . $grandEqsl    . '</th>
					<th>' . $grandQrz     . '</th>
					<th>' . $grandClublog . '</th>
				</tr>
			</tfoot>
		</table>
		</div></div>';
	}
	?>
	<div class="tables-container mx-2">
	<?php
	if ($qsoarray) {
		foreach($bands as $band) {
			echo '
			<div class="table-wrapper">
				<table style="width: 100%" class="flex-wrap table-sm table table-bordered table-hover table-striped table-condensed text-center">
					<thead>';
						echo '<tr>';
						echo '<th colspan = 7>' . $band . '</th>';
						echo '</tr>
					</thead>
					<tbody>';
					echo '<tr><th></th>';
						echo '<th>QSO</th>';
						echo '<th>QSL</th>';
						echo '<th>LoTW</th>';
						echo '<th>eQSL</th>';
						echo '<th>QRZ</th>';
						echo '<th>Clublog</th>';
					echo '</tr>';
			$qsototal = 0;
			$qsltotal = 0;
			$lotwtotal = 0;
			$eqsltotal = 0;
			$qrztotal = 0;
			$clublogtotal = 0;
			foreach ($qsoarray as $mode => $value) {
				$qsototal += $value[$band]['qso'] ?? 0;
				$qsltotal += $value[$band]['qsl'] ?? 0;
				$lotwtotal += $value[$band]['lotw'] ?? 0;
				$eqsltotal += $value[$band]['eqsl'] ?? 0;
				$qrztotal += $value[$band]['qrz'] ?? 0;
				$clublogtotal += $value[$band]['clublog'] ?? 0;
				$total = ($value[$band]['qso'] ?? 0) + ($value[$band]['qsl'] ?? 0) + ($value[$band]['lotw'] ?? 0) + ($value[$band]['eqsl'] ?? 0) + ($value[$band]['qrz'] ?? 0) + ($value[$band]['clublog'] ??0 );
				if ($total > 0) {
					echo '<tr>
							<th>'. $mode .'</th>';
						echo '<td>' . $value[$band]['qso'] . '</td>';
						echo '<td>' . $value[$band]['qsl'] . '</td>';
						echo '<td>' . $value[$band]['lotw'] . '</td>';
						echo '<td>' . $value[$band]['eqsl'] . '</td>';
						echo '<td>' . $value[$band]['qrz'] . '</td>';
						echo '<td>' . $value[$band]['clublog'] . '</td>';
					echo '</tr>';
				}
			}
			echo '</tbody><tfoot><tr><th>'.__("Total").'</th>';
			echo '<th>' . $qsototal . '</th>';
			echo '<th>' . $qsltotal . '</th>';
			echo '<th>' . $lotwtotal . '</th>';
			echo '<th>' . $eqsltotal . '</th>';
			echo '<th>' . $qrztotal . '</th>';
			echo '<th>' . $clublogtotal . '</th>';
			echo '</tr></tfoot></table></div>';
		}
	}
	if ($qsosatarray) {
		foreach($sats as $sat) {
			echo '
			<div class="table-wrapper">
				<table style="width: 100%" class="mx-2 flex-wrap table-sm table table-bordered table-hover table-striped table-condensed text-center">
					<thead>';
						echo '<tr>';
						echo '<th colspan = 7>' . $sat . '</th>';
						echo '</tr>
					</thead>
					<tbody>';
					echo '<tr><th></th>';
						echo '<th>QSO</th>';
						echo '<th>QSL</th>';
						echo '<th>LoTW</th>';
						echo '<th>eQSL</th>';
						echo '<th>QRZ</th>';
						echo '<th>Clublog</th>';
					echo '</tr>';
			$qsototal = 0;
			$qsltotal = 0;
			$lotwtotal = 0;
			$eqsltotal = 0;
			$qrztotal = 0;
			$clublogtotal = 0;
			foreach ($qsosatarray as $mode => $value) {
				$qsototal += $value[$sat]['qso'] ?? 0;
				$qsltotal += $value[$sat]['qsl'] ?? 0;
				$lotwtotal += $value[$sat]['lotw'] ?? 0;
				$eqsltotal += $value[$sat]['eqsl'] ?? 0;
				$qrztotal += $value[$sat]['qrz'] ?? 0;
				$clublogtotal += $value[$sat]['clublog'] ?? 0;
				$total = ($value[$sat]['qso'] ?? 0) + ($value[$sat]['qsl'] ?? 0) + ($value[$sat]['lotw'] ?? 0) + ($value[$sat]['eqsl'] ?? 0) + ($value[$sat]['qrz'] ?? 0) + ($value[$sat]['clublog'] ?? 0);
				if ($total > 0) {
				echo '<tr>
						<th>'. $mode .'</th>';
					echo '<td>' . $value[$sat]['qso'] . '</td>';
					echo '<td>' . $value[$sat]['qsl'] . '</td>';
					echo '<td>' . $value[$sat]['lotw'] . '</td>';
					echo '<td>' . $value[$sat]['eqsl'] . '</td>';
					echo '<td>' . $value[$sat]['qrz'] . '</td>';
					echo '<td>' . $value[$sat]['clublog'] . '</td>';
				echo '</tr>';
				}
			}
			echo '</tbody><tfoot><tr><th>'.__("Total").'</th>';
			echo '<th>' . $qsototal . '</th>';
			echo '<th>' . $qsltotal . '</th>';
			echo '<th>' . $lotwtotal . '</th>';
			echo '<th>' . $eqsltotal . '</th>';
			echo '<th>' . $qrztotal . '</th>';
			echo '<th>' . $clublogtotal . '</th>';
			echo '</tr></tfoot></table></div>';
		}
	}
	?>
	</div>
</div>
</div>
