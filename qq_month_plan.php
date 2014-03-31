  <?php
  require_once( 'core.php' );
  $t_core_path = config_get( 'core_path' );
  require_once( $t_core_path.'bug_api.php' );

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
  $k_month = date('Ym',mktime(0,0,0,$month,1,$year));

  if ($k_month < date('Ym',strtotime('now'))) $date_disabled = 'disabled';

  if ( strtotime('now') < strtotime($c_date)) $proc_disabled = 'disabled';
  if ( strtotime('now') > strtotime("+ 1 week",strtotime("+ 1 month",strtotime($c_date)))) $proc_disabled = 'disabled';

//если отправлена таблица - вносим изменения
  if ($_POST['table']=='X') {
//считываем таблицы
  $user_id=$_POST['user_id'];
  $t_user_id=$_POST['tuser_id'];
  $t_bug_id = $_POST['bug_id'];
  $t_begin = $_POST['begin'];
  $t_end = $_POST['end'];
  $t_time = $_POST['time'];
  $t_row_exist = $_POST['row_exist'];

  foreach ($t_bug_id as $r => $value) {
    $c_time = helper_duration_to_minutes($t_time[$r]);
//если запись не пустая, проверяем правильность заполнения
    if ($t_begin[$r]+$t_end[$r]+$c_time+$t_procent[$r]<>0) {
      if ($t_begin[$r]==0) $t_begin[$r] = 1;
      if ($t_end[$r]==0) $t_end[$r] = cal_days_in_month(CAL_GREGORIAN, $month, $year);
      if ($t_begin[$r]>$t_end[$r]){
        $t_tmp = $t_begin[$r];
        $t_begin[$r] = $t_end[$r];
        $t_end[$r] = $t_tmp;
      } 
    }  
    if ($t_begin[$r]+$t_end[$r]==0) {
      $query = 'delete from ugmk_month_plan where bug_id='.$t_bug_id[$r].' and month='.$k_month.' and user_id='.$t_user_id[$r]; 
    } else {
      if ($t_row_exist[$r]=='X'){
      $query = 'update ugmk_month_plan set begin_date = '.$t_begin[$r].',end_date='.$t_end[$r].',time_plan='.$c_time.' where bug_id='.$t_bug_id[$r].' and month='.$k_month.
        ' and user_id='.$t_user_id[$r]; 
      } else {
      $query = 'insert into ugmk_month_plan (bug_id,user_id,month,begin_date,end_date,time_plan) values('.$t_bug_id[$r].','.$t_user_id[$r].','.$k_month.','.$t_begin[$r].','.$t_end[$r].','
        .$c_time.')'; 
      } 
    } 
    $db_plan = db_query($query);
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
  $t_date_where = " AND b.date_submitted <= ".strtotime("+ 1 month",strtotime($c_date));
  $t_date_where .= " AND fd.value >=".strtotime($c_date);
  $query = "select p.name, b.id, summary,b.status,u.id as user_id,u.realname,d.division,d.company,d.departament,c.name as category, fd.value as last_date
              FROM mantis_bug_table b join mantis_project_table p on p.id = b.project_id
                join mantis_category_table c on c.id = b.category_id
                join mantis_custom_field_string_table fd on b.id = fd.bug_id and fd.field_id = 3 /*план/крайний срок*/
                left outer join mantis_custom_field_string_table fu on b.id = fu.bug_id and fu.field_id = 10 /*ответвенный по плану*/
                left outer join mantis_user_table u on u.realname = fu.value
                left outer join ugmk_user_table d on u.id = d.id
              WHERE b.view_state = 10 /* and c.id <> 88 */ /*План на год*/
                $t_date_where $clause_user $clause_project";
  $db_answer = db_query($query);

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
</form>
<br/>

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
      <td class="small-caption"> компания </td>
      <td class="small-caption"> отдел </td>
      <td class="small-caption"> подразделение </td>
      <td class="small-caption"> ответсвенный по плану </td>
      <td class="small-caption"> проект </td>
      <td class="small-caption"> категория </td>
	    <td class="small-caption"><?php echo lang_get('summary')?></td>
	    <td class="small-caption"> плановая дата окончания </td>
      <td class="small-caption"> дата начала </td>
	    <td class="small-caption"> дата окончания </td>
	    <td class="small-caption"> плановые трудозатраты </td>
    </tr>
<?php
  $row = 0; $tabindex = 10000;
  foreach ( $db_answer as $t_item ) {
    $begin_date = 0; $end_date = 0; $exec_proc = 0; $row_exist = ''; $time_plan = '00:00';
    $query = 'SELECT * FROM  ugmk_month_plan where bug_id='.$t_item['id'].' and month='.$k_month; 
    $db_plan = db_query($query);
    foreach ( $db_plan as $r_plan ) {
      $begin_date = $r_plan['begin_date'];
      $end_date = $r_plan['end_date'];
      $time_plan = $r_plan['time_plan'];
//      $exec_proc = $r_plan['exec_proc'];
      $row_exist = 'X';
    }
    if((($begin_date+$end_date)>0)||($t_item['status']<80)) {
      $status_color = get_status_color( $t_item['status'] );
      echo '<tr bgcolor="', $status_color, '" border="1">';
      echo '<td>'.$t_item['company'].'</td>';
      echo '<td>'.$t_item['division'].'</td>';
      echo '<td>'.$t_item['departament'].'</td>';
      echo '<td>'.$t_item['realname'].'</td>';
      echo '<td>'.$t_item['name'].'</td>';
      echo '<td>'.$t_item['category'].'</td>';
      $t_link = string_get_bug_view_link( $t_item['id'] ) . ": " . string_display( $t_item['summary'] );
      echo '<td>'.$t_link.'</td>';
      echo '<td>'.date('Y-m-d',custom_field_get_value( 3, $t_item['id'])).'</td>';
      echo '<td>';
      echo '<input type="hidden" name="bug_id['.$row.']" value="'.$t_item['id'].'"/>';
      echo '<input type="hidden" name="tuser_id['.$row.']" value="'.$t_item['user_id'].'"/>';
      echo '<input type="hidden" name="row_exist['.$row.']" value="'.$row_exist.'"/>';
      $select = '<select name="begin['.$row.']" '.$date_disabled.'>';
      for($i=0; $i<=cal_days_in_month(CAL_GREGORIAN, $month, $year); $i++){
        $select .= "<option value=\"".$i."\"";
        if ($begin_date == $i) $select .= " selected=\"selected\"";
        $select .= ">".(($i==0)?'':$i.'-'.iconv("CP1251", "UTF-8",strftime('%A',mktime(0,0,0,$month,$i,$year))))."</option>";
      }
      $select .= "</select>";
      echo $select.'</td>';
      echo '<td>';
      $select = '<select name="end['.$row.']" '.$date_disabled.'>';
      for($i=0; $i<=cal_days_in_month(CAL_GREGORIAN, $month, $year); $i++){
        $select .= "<option value=\"".$i."\"";
        if ($end_date == $i) $select .= " selected=\"selected\"";
        $select .= ">".(($i==0)?'':$i.'-'.iconv("CP1251", "UTF-8",strftime('%A',mktime(0,0,0,$month,$i,$year))))."</option>";
      }
      $select .= "</select>";
      echo $select.'</td>';
      echo '<td><input name="time['.$row.']" size="5" tabindex="'.$tabindex.'" value="'.db_minutes_to_hhmm($time_plan).'" '.$date_disabled.'/></td>';
      echo '</tr>';
    }
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
