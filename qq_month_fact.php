<?php
  require_once( 'core.php' );
  $t_core_path = config_get( 'core_path' );
  require_once( $t_core_path.'bug_api.php' );
  require_once "Spreadsheet/Excel/Writer.php";

  $t_user_glob_level = access_get_global_level($t_user_id);

  $project_id = helper_get_current_project();
  $user_id = db_prepare_int($_POST['handler_id'][0]);
  if(!isset($_POST['handler_id'])) $user_id = -1;
  if ($t_user_glob_level<70) $user_id = auth_get_current_user_id();
  
  $company = $_POST['company'];
  $division = $_POST['division'];
  $departament = $_POST['departament'];

  if ($t_user_glob_level<90) {
    $query = 'SELECT * FROM ugmk_user_table where id = '.auth_get_current_user_id();
    $db_manager = db_query($query);
    foreach ( $db_manager as $t_manager ) {
      $company = $t_manager['company'];
      $division = $t_manager['division'];
      $departament = $t_manager['departament'];
    }
// если не заполненны орг.данные, то может смотреть только себя
    if ((empty($company))||(empty($division))||(empty($departament))) {
      $user_id = auth_get_current_user_id();
    }
  }

  $month=$_POST['month'];
  if (empty($_POST['month'])) $month=date('m');
  
  $year=$_POST['year'];
  if (empty($_POST['year'])) $year=date('Y');

  $c_date = date('Y-m-d',mktime(0,0,0,$month,1,$year));
  $k_month = date('Ym',  mktime(0,0,0,$month,1,$year));

  if ( strtotime('now') < strtotime($c_date)) $proc_disabled = 'disabled';
  if ( strtotime('now') > strtotime("+ 1 week",strtotime("+ 1 month",strtotime($c_date)))) $proc_disabled = 'disabled';

//если отправлена таблица - вносим изменения
  if ($_POST['table']=='X') {
//считываем таблицы
    $user_id=$_POST['user_id'];
    $t_bug_id = $_POST['bug_id'];
    $t_procent = $_POST['procent'];
    $t_row_exist = $_POST['row_exist'];

    foreach ($t_bug_id as $r => $value) {
      if ($t_procent[$r]==0) {
        $query = 'delete from ugmk_month_fact where bug_id='.$t_bug_id[$r].' and month='.$k_month; 
      } else {
        if ($t_row_exist[$r]=='X'){
        $query = 'update ugmk_month_fact set exec_proc='.$t_procent[$r].' where bug_id='.$t_bug_id[$r].' and month='.$k_month; 
        } else {
        $query = 'insert into ugmk_month_fact (bug_id,month,exec_proc) values('.$t_bug_id[$r].','.$k_month.','.$t_procent[$r].')'; 
        } 
      } 
      $db_fact = db_query($query);
      //echo $query;
    }
  } // окончание сохранения таблицы

  $t_filter['handler_id'] =  $user_id;
  
// формируем запрос 
  if ( $user_id == -2 ) $clause_user = " AND 1 = 2 ";
  if ( $user_id  >  0 )   $clause_user = " AND u.id = '$user_id' ";
  if ( $user_id == -1 ){
    $c_user = auth_get_current_user_id();
    $clause_user = " AND u.id = '$c_user' ";
  }
  if(!empty($company)) $clause_user .= " and d.company='$company' ";
  if(!empty($division)) $clause_user .= " and d.division='$division' ";
  if(!empty($departament)) $clause_user .= " and d.departament='$departament' ";
  if ($project_id == 0)
    $clause_project = '';
  else {
    $clause_project = ' AND ( b.project_id = '.$project_id.' ';
    $c_all_pj = project_hierarchy_get_all_subprojects( $project_id );
//echo implode(',',$c_all_pj);    
    foreach ($c_all_pj as $p => $value) {
       $clause_project .= ' OR b.project_id = '.$c_all_pj[$p].' ';
      };
    $clause_project .= ' ) ';
    };
  $date_clause =  " AND bn.date_submitted <  ".strtotime("+ 1 month",strtotime($c_date));
  $date_clause .= " AND bn.date_submitted >= ".strtotime($c_date);

  $query = "
select b.category_id,pf.month,
  /*COALESCE(bh.id,if(b.category_id=88,b.id,NULL)) g_id,COALESCE(bh.summary,if(b.category_id=88,b.summary,NULL)) g_summary,*/
  b.id,b.summary,pl.begin_date, pl.end_date,fp.value as plan_date,c.name as category_name,d.company, d.division, d.departament, d.phone,
  fi.value as init,b.status,fk.value as curator,u.realname,pl.time_plan, sum(bn.time_tracking) as time_tracking,
  ft.value as typ,fd.value as doc,fm.value as modul, fc.value as class, fr.value as result,pf.exec_proc
  FROM mantis_bug_table b join mantis_project_table p on p.id = b.project_id
    join mantis_category_table c on c.id = b.category_id
    join mantis_bugnote_table bn on b.id = bn.bug_id
    left outer join mantis_custom_field_string_table fp on b.id = fp.bug_id and fp.field_id = 3  /*плановая дата*/
    left outer join mantis_custom_field_string_table fi on b.id = fi.bug_id and fi.field_id = 6  /*обратился*/
    left outer join mantis_custom_field_string_table ft on b.id = ft.bug_id and ft.field_id = 12 /*тип задачи*/
    left outer join mantis_custom_field_string_table fd on b.id = fd.bug_id and fd.field_id = 13 /*подтверждающий документ*/
    left outer join mantis_custom_field_string_table fm on b.id = fm.bug_id and fm.field_id = 14 /*модуль*/
    left outer join mantis_custom_field_string_table fc on b.id = fc.bug_id and fc.field_id = 15 /*класс*/
    left outer join mantis_custom_field_string_table fr on b.id = fr.bug_id and fr.field_id = 16 /*результат*/
    left outer join mantis_custom_field_string_table fk on b.id = fk.bug_id and fk.field_id = 7  /*куратор*/
    left outer join mantis_custom_field_string_table fw on b.id = fw.bug_id and fw.field_id = 10 /*план-ответственный*/
    left outer join mantis_user_table u on u.realname = fw.value
    left outer join ugmk_user_table d on u.id = d.id
    left outer join ugmk_month_plan pl on b.id = pl.bug_id  and pl.month = $k_month  
    left outer join ugmk_month_fact pf on b.id = pf.bug_id  and pf.month = $k_month  
/*    left outer join mantis_bug_relationship_table r on b.id = r.destination_bug_id and r.relationship_type = 2
    left outer join mantis_bug_table bh on bh.id = r.source_bug_id and bh.category_id = 88 */
  WHERE b.view_state = 10 $clause_user $clause_project $date_clause
  GROUP BY /*bh.id,bh.summary,*/ b.category_id,pf.month,
    b.id,b.summary,pl.begin_date, pl.end_date,fp.value,c.name,d.company, d.division, d.departament,
    d.phone,fi.value,b.status,fk.value,u.realname,pl.time_plan,ft.value,fd.value,fm.value,fc.value,fr.value,pf.exec_proc";

    echo $query;
  $db_answer = db_query($query);

  if (isset($_POST['download_xls'])) {
    $xls =& new Spreadsheet_Excel_Writer();
    $xls->send("month_report$k_month.xls");
    $sheet = & $xls->addWorksheet('Sheet1');

    $head_format =& $xls->addFormat();
    $head_format->setBold();
    $head_format->setColor('yellow');
    $head_format->setFgColor('blue');

    $time_format =& $xls->addFormat();
    $time_format->setNumFormat('[H]:MM');

    $sheet->write(0, 0,iconv('UTF-8','windows-1251','№ '),$head_format);
    $sheet->write(0, 1,iconv('UTF-8','windows-1251','годовой план '),$head_format);
    $sheet->write(0, 2,iconv('UTF-8','windows-1251','№ '),$head_format);
    $sheet->write(0, 3,iconv('UTF-8','windows-1251','инцидент '),$head_format);
    $sheet->write(0, 4,iconv('UTF-8','windows-1251','план на месяц от '),$head_format);
    $sheet->write(0, 5,iconv('UTF-8','windows-1251','план на месяц до '),$head_format);
    $sheet->write(0, 6,iconv('UTF-8','windows-1251','плановое окончание'),$head_format);
    $sheet->write(0, 7,iconv('UTF-8','windows-1251','категория '),$head_format);
    $sheet->write(0, 8,iconv('UTF-8','windows-1251','подразделение '),$head_format);
    $sheet->write(0, 9,iconv('UTF-8','windows-1251','отдел '),$head_format);
    $sheet->write(0,10,iconv('UTF-8','windows-1251','бюро '),$head_format);
    $sheet->write(0,11,iconv('UTF-8','windows-1251','ответственный по плану '),$head_format);
//    $sheet->write(0,12,iconv('UTF-8','windows-1251','телефон '),$head_format);
    $sheet->write(0,13,iconv('UTF-8','windows-1251','подр.инициатор '),$head_format);
    $sheet->write(0,14,iconv('UTF-8','windows-1251','статус '),$head_format);
    $sheet->write(0,15,iconv('UTF-8','windows-1251','куратор '),$head_format);
    $sheet->write(0,16,iconv('UTF-8','windows-1251','плановые трудозатраты'),$head_format);
    $sheet->write(0,17,iconv('UTF-8','windows-1251','факт.трудозатраты '),$head_format);
    $sheet->write(0,18,iconv('UTF-8','windows-1251','% завершения '),$head_format);
    $sheet->write(0,19,iconv('UTF-8','windows-1251','тип задачи '),$head_format);
    $sheet->write(0,20,iconv('UTF-8','windows-1251','подтв.документ '),$head_format);
    $sheet->write(0,21,iconv('UTF-8','windows-1251','модуль(подсистема) '),$head_format);
    $sheet->write(0,22,iconv('UTF-8','windows-1251','кдасс задачи'),$head_format);
    $sheet->write(0,23,iconv('UTF-8','windows-1251','планируемый результат '),$head_format);
    $i = 1;
    foreach ( $db_answer as $t_item ) {
      if($t_item['category_id']==88){
        $g_id = $t_item['id'];
        $g_summary = $t_item['summary'];      
      } else {
        $g_id = '';
        $g_summary = '';      
        $query = 'select bh.id, bh.summary from mantis_bug_relationship_table r join mantis_bug_table bh on bh.id = r.source_bug_id and bh.category_id = 88 
                  where r.destination_bug_id = '.$t_item['g_id'].' and r.relationship_type = 2
                  limit 0,1';
        $db_g_bug = db_query($query);
        foreach ( $db_g_bug as $t_g_bug ) {
          $g_id = $t_g_bug['id'];
          $g_summary = $t_g_bug['summary'];      
        }
      } 
      $sheet->write($i,0,iconv('UTF-8','windows-1251',$g_id));
      $sheet->write($i,1,iconv('UTF-8','windows-1251',$g_summary));
      $sheet->write($i,2,iconv('UTF-8','windows-1251',$t_item['id']));
      $sheet->write($i,3,iconv('UTF-8','windows-1251',$t_item['summary']));
      $sheet->write($i,4,((empty($t_item['begin_date']))?'':date('Y-m-d H:i:s',mktime(0,0,0,$month,$t_item['begin_date'],$year))));
      $sheet->write($i,5,((empty($t_item['end_date']))?'':date('Y-m-d H:i:s',mktime(0,0,0,$month,$t_item['end_date'],$year))));
      $sheet->write($i,6,((empty($t_item['plan_date']))?'':date('Y-m-d',$t_item['plan_date'])));
      $sheet->write($i,7,iconv('UTF-8','windows-1251',$t_item['category_name']));
      $sheet->write($i,8,iconv('UTF-8','windows-1251',$t_item['company']));
      $sheet->write($i,9,iconv('UTF-8','windows-1251',$t_item['division']));
      $sheet->write($i,10,iconv('UTF-8','windows-1251',$t_item['departament']));
      $sheet->write($i,11,iconv('UTF-8','windows-1251',$t_item['realname']));
//      $sheet->write($i,12,iconv('UTF-8','windows-1251',$t_item['phone']));
      $sheet->write($i,13,iconv('UTF-8','windows-1251',$t_item['init']));
      $sheet->write($i,14,iconv('UTF-8','windows-1251',get_enum_element( 'status', $t_item['status'])));
      $sheet->write($i,15,iconv('UTF-8','windows-1251',$t_item['curator']));
      $sheet->write($i,16,$t_item['time_plan']/(60*24 ),$time_format);
      $sheet->write($i,17,$t_item['time_tracking']/(60*24),$time_format);
      $sheet->write($i,18,$t_item['exec_proc']);
      $sheet->write($i,19,iconv('UTF-8','windows-1251',$t_item['typ']));
      $sheet->write($i,20,iconv('UTF-8','windows-1251',$t_item['doc']));
      $sheet->write($i,21,iconv('UTF-8','windows-1251',$t_item['modul']));
      $sheet->write($i,22,iconv('UTF-8','windows-1251',$t_item['class']));
      $sheet->write($i,23,iconv('UTF-8','windows-1251',$t_item['result']));
      $i++;
    }

    $xls->close();
    die();
  }


  html_page_top1( lang_get( 'time_tracking_billing_link' ));
  echo "<script type=\"text/javascript\" src=\"" . $t_path . "javascript/sorttable.js\"></script> \n";
  html_page_top2();
  setlocale (LC_ALL, 'Russian_Russia.1251');
//echo $query;
//echo $t_user_glob_level;
?>  
<br/>
<form name="summary_page" action="?" method="POST">
<?php

  echo select_ugmk_org( 'company', $company);
  echo select_ugmk_org( 'division', $division);
  echo select_ugmk_org( 'departament', $departament);

  print_filter_handler_id();
  $select = "<select name=\"month\" >";
  for($i=1; $i<=12; $i++){
    $select .= "<option value=\"".$i."\"";
    if ($i == $month) $select .= " selected=\"selected\"";
    $select .= ">".iconv("CP1251", "UTF-8",strftime('%B',mktime(0,0,0,$i,1,$year)))."</option>";
    }
  $select .= "</select>";
  $select .= "<input type=text name=year size=4 value=$year>";
  echo $select;
?>
  <input type="submit" value="Submit">
  <input type="submit" name="download_xls" value="Excel">
</form><br/>

<form method="POST" action="?">
<?php
  echo '<input type="hidden" name="month" value='.$month.'>';
  echo '<input type="hidden" name="year" value='.$year.'>';
  echo '<input type="hidden" name="table" value="X">';
  echo '<input type="hidden" name="user_id" value="'.$user_id.'">';
  echo '<input type="hidden" name="company" value="'.$company.'">';
  echo '<input type="hidden" name="division" value="'.$division.'">';
  echo '<input type="hidden" name="departament" value="'.$departament.'">';
?>
  <table border="0" width="100%"  cellspacing="1" class="sortable">
    <tr <?php echo helper_alternate_class() ?>>
      <td class="small-caption"> № </td>
      <td class="small-caption"> годовой план </td>
      <td class="small-caption"> № </td>
      <td class="small-caption"> инцидент </td>
      <td class="small-caption"> план на месяц от </td>
	    <td class="small-caption"> план на месяц до </td>
      <td class="small-caption"> плановое окончание</td>
	    <td class="small-caption"> категория </td>
      <td class="small-caption"> подразделение </td>
      <td class="small-caption"> отдел </td>
	    <td class="small-caption"> бюро </td>
      <td class="small-caption"> ответственный по плану </td>
      <td class="small-caption"> подр.инициатор </td>
      <td class="small-caption"> статус </td>
      <td class="small-caption"> куратор </td>
      <td class="small-caption"> плановые трудозатраты</td>
      <td class="small-caption"> факт.трудозатраты </td>
      <td class="small-caption"> % завершения </td>
      <td class="small-caption"> тип задачи </td>
      <td class="small-caption"> подтв.документ </td>
      <td class="small-caption"> модуль(подсистема) </td>
      <td class="small-caption"> кдасс задачи</td>
      <td class="small-caption"> планируемый результат </td>
    </tr>
<?php
  $row = 0;
  $tabindex = 10000;
  foreach ( $db_answer as $t_item ) {
    $row_exist = '';$exec_proc = 0;
    if(!empty($t_item['month'])) {
      $exec_proc = $t_item['exec_proc'];
      $row_exist = 'X';
    }

//    if((($begin_date+$end_date)>0)||($t_item['status']<80)) {
      $status_color = get_status_color( $t_item['status'] );
      echo '<tr bgcolor="', $status_color, '" border="1">';
      if($t_item['category_id']==88){
        $g_id = $t_item['id'];
        $g_summary = $t_item['summary'];      
      } else {
        $g_id = '';
        $g_summary = '';      
        $query = 'select bh.id, bh.summary from mantis_bug_relationship_table r join mantis_bug_table bh on bh.id = r.source_bug_id and bh.category_id = 88 
                  where r.destination_bug_id = '.$t_item['id'].' and r.relationship_type = 2
                  limit 0,1';
        $db_g_bug = db_query($query);
        foreach ( $db_g_bug as $t_g_bug ) {
          $g_id = $t_g_bug['id'];
          $g_summary = $t_g_bug['summary'];      
        }
      } 
      echo '<td>'.((empty($g_id))?'':string_get_bug_view_link( $g_id)).'</td>';
      echo '<td>'.$g_summary.'</td>';
      echo '<td>'.string_get_bug_view_link( $t_item['id'] );
      echo '<input type="hidden" name="bug_id['.$row.']" value="'.$t_item['id'].'"/>';
      echo '<input type="hidden" name="row_exist['.$row.']" value="'.$row_exist.'"/>';
      echo '</td>';
      echo '<td>'.$t_item['summary'].'</td>';
      echo '<td>'.((empty($t_item['begin_date']))?'':date('Y-m-d',mktime(0,0,0,$month,$t_item['begin_date'],$year))).'</td>';
      echo '<td>'.((empty($t_item['end_date']))?'':date('Y-m-d',mktime(0,0,0,$month,$t_item['end_date'],$year))).'</td>';
      echo '<td>'.((empty($t_item['plan_date']))?'':date('Y-m-d',$t_item['plan_date'])).'</td>';
      echo '<td>'.$t_item['category_name'].'</td>';
      echo '<td>'.$t_item['company'].'</td>';
      echo '<td>'.$t_item['division'].'</td>';
      echo '<td>'.$t_item['departament'].'</td>';
      echo '<td>'.$t_item['realname'].'</td>';
//      echo '<td>'.$t_item['phone'].'</td>';
      echo '<td>'.$t_item['init'].'</td>';
      echo '<td>'.get_enum_element( 'status', $t_item['status']).'</td>';
      echo '<td>'.$t_item['curator'].'</td>';
      echo '<td>'.db_minutes_to_hhmm($t_item['time_plan']).'</td>';
      echo '<td>'.db_minutes_to_hhmm($t_item['time_tracking']).'</td>';
      echo '<td><input type="number" size="5" tabindex="'.$tabindex.'" min="00" max="100" name="procent['.$row.']" value="'.$exec_proc.'" '.$proc_disabled.'/></td>';
      echo '<td>'.$t_item['typ'].'</td>';
      echo '<td>'.$t_item['doc'].'</td>';
      echo '<td>'.$t_item['modul'].'</td>';
      echo '<td>'.$t_item['class'].'</td>';
      echo '<td>'.$t_item['result'].'</td>';
      echo '</tr>';
  $row++;  $tabindex++;
	}
?>
</table>
  <div style="width: 400px;">
    <input type="submit" value="Submit">
  </div>
</form>
<?php
  html_page_bottom1( __FILE__ );
?>
