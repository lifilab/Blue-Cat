/* ── Empleados - Blue-Cat HRM ── */
var _st = null, _profileId = 0;
document.addEventListener('DOMContentLoaded', function() { loadEmp(); });

function $(id) { return document.getElementById(id); }
function fm(n) { if(n===null||n===undefined)return'$0'; return '$'+Math.round(Number(n)).toLocaleString('es-CL'); }
function esc(s) { if(!s)return''; var d=document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
function h(s){return esc(s)||'-';}
function bd(s){return s?esc(s):'-';}

function toast(msg,t){
  var el=document.createElement('div');
  el.className='toast toast-'+(t==='err'?'err':'ok');
  el.innerHTML=msg;
  document.body.appendChild(el);
  requestAnimationFrame(function(){el.classList.add('show');});
  setTimeout(function(){el.classList.remove('show');setTimeout(function(){el.remove();},300);},2500);
}

function ds(){clearTimeout(_st);_st=setTimeout(function(){loadEmp();},300);}

/* ── Load ── */
function loadEmp(){
  var q=$('sq').value,f=$('sf').value;
  var url='../assets/api/empleados.php?q='+encodeURIComponent(q);
  if(f)url+='&estado='+f;
  var xhr=new XMLHttpRequest();
  xhr.open('GET',url,true);
  xhr.onload=function(){
    if(xhr.status!==200)return;
    var d=JSON.parse(xhr.responseText);
    d = d.items || d;
    renderTable(d);updateKPIs(d);
  };
  xhr.send();
}

function updateKPIs(d){
  var t=0,a=0,i=0,l=0,v=0,du=0;
  for(var i2=0;i2<d.length;i2++){
    t++;var s=d[i2].estado;
    if(s==='ACTIVO')a++;
    else if(s==='INACTIVO')i++;
    else if(s==='CON_LICENCIA')l++;
    else if(s==='VACACIONES')v++;
    else if(s==='DESVINCULADO')du++;
  }
  $('kp-total').textContent=t;
  $('kp-activos').textContent=a;
  $('kp-inactivos').textContent=i;
  $('kp-licencia').textContent=l;
  $('kp-vacaciones').textContent=v;
  $('kp-desvinculados').textContent=du;
}

function renderTable(d){
  var tb=$('etb'),e=$('ee');
  tb.innerHTML='';
  if(!d||!d.length){e.style.display='block';return;}
  e.style.display='none';
  for(var i=0;i<d.length;i++){
    var r=d[i];
    var bc='badge-'+(r.estado||'ACTIVO');
    var tr=document.createElement('tr');
    tr.innerHTML=
      '<td><strong>'+esc(r.codigo)+'</strong></td>'+
      '<td>'+bd(r.rut)+'</td>'+
      '<td><a href="#" onclick="showProfile('+r.id_empleado+')" style="color:#4f46e5;text-decoration:none;font-weight:500;">'+esc(r.nombres)+' '+esc(r.apellidos)+'</a></td>'+
      '<td>'+bd(r.cargo)+'</td>'+
      '<td>'+bd(r.departamento)+'</td>'+
      '<td>'+bd(r.tipo_contrato)+'</td>'+
      '<td><span class="badge '+bc+'">'+r.estado+'</span></td>'+
      '<td style="font-size:12px;color:#64748b;">'+bd(r.fecha_ingreso)+'</td>'+
      '<td>'+
      (r.id_user ? '<span onclick="event.stopPropagation();showCrearCredenciales('+r.id_empleado+')" style="display:inline-block;background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;margin-right:4px;cursor:pointer;" title="Gestionar credenciales"><i class="fas fa-key"></i></span>' : '')+
      '<button class="bi" onclick="showProfile('+r.id_empleado+')" title="Ver perfil"><i class="fas fa-eye"></i></button>'+
      '<button class="bi" onclick="showEdit('+r.id_empleado+')" title="Editar"><i class="fas fa-pen"></i></button>'+
      (r.estado!=='DESVINCULADO'?'<button class="bi red" onclick="cambiarEstado('+r.id_empleado+',\'DESVINCULADO\')" title="Desvincular"><i class="fas fa-user-minus"></i></button>':'<button class="bi green" onclick="cambiarEstado('+r.id_empleado+',\'ACTIVO\')" title="Reactivar"><i class="fas fa-user-check"></i></button>')+
      '<button class="bi red" onclick="eliminarEmpleado('+r.id_empleado+',\''+esc(r.nombres)+' '+esc(r.apellidos)+'\')" title="Eliminar"><i class="fas fa-trash"></i></button>'+
      '</td>';
    tb.appendChild(tr);
  }
}

/* ── Create ── */
function showCreate(){
  var m=$('cm'),b=$('cbody');
  b.innerHTML=
    '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-user-plus" style="color:#4f46e5;"></i> Nuevo Empleado</h3>'+
    '<div class="gr2">'+
    '<div class="fld"><label>RUT</label><input id="c-rut" placeholder="12.345.678-9"></div>'+
    '<div class="fld"><label>Nombres *</label><input id="c-nombres" placeholder="Nombres"></div>'+
    '<div class="fld"><label>Apellidos *</label><input id="c-apellidos" placeholder="Apellidos"></div>'+
    '<div class="fld"><label>Fecha Nacimiento</label><input id="c-fn" type="date"></div>'+
    '<div class="fld"><label>Sexo</label><select id="c-sexo"><option value="">Seleccionar</option><option value="M">Masculino</option><option value="F">Femenino</option><option value="Otro">Otro</option></select></div>'+
    '<div class="fld"><label>Estado Civil</label><select id="c-ec"><option value="">Seleccionar</option><option value="Soltero/a">Soltero/a</option><option value="Casado/a">Casado/a</option><option value="Divorciado/a">Divorciado/a</option><option value="Viudo/a">Viudo/a</option></select></div>'+
    '<div class="fld"><label>Correo Personal</label><input id="c-mailp" type="email"></div>'+
    '<div class="fld"><label>Correo Corporativo</label><input id="c-mailc" type="email"></div>'+
    '<div class="fld"><label>Teléfono</label><input id="c-tel"></div>'+
    '<div class="fld"><label>Celular</label><input id="c-cel"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Dirección</label><input id="c-dir"></div>'+
    '<div class="fld"><label>Comuna</label><input id="c-comuna"></div>'+
    '<div class="fld"><label>Ciudad</label><input id="c-ciudad"></div>'+
    '</div>'+
    '<h4 style="font-size:14px;font-weight:600;color:#1e293b;margin:12px 0 8px;border-top:1px solid #e2e8f0;padding-top:12px;"><i class="fas fa-briefcase"></i> Información Laboral</h4>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Cargo</label><input id="c-cargo"></div>'+
    '<div class="fld"><label>Departamento</label><input id="c-depto"></div>'+
    '<div class="fld"><label>Sucursal</label><input id="c-suc"></div>'+
    '<div class="fld"><label>Centro Costo</label><input id="c-cc"></div>'+
    '<div class="fld"><label>Jefe Directo</label><input id="c-jefe"></div>'+
    '<div class="fld"><label>Fecha Ingreso</label><input id="c-fi" type="date"></div>'+
    '<div class="fld"><label>Tipo Contrato</label><select id="c-tc"><option value="">Seleccionar</option><option value="Indefinido">Indefinido</option><option value="Plazo Fijo">Plazo Fijo</option><option value="Honorarios">Honorarios</option><option value="Part-time">Part-time</option><option value="Práctica">Práctica</option></select></div>'+
    '<div class="fld"><label>Modalidad</label><select id="c-modal"><option value="Presencial">Presencial</option><option value="Remoto">Remoto</option><option value="Híbrido">Híbrido</option></select></div>'+
    '<div class="fld"><label>Sueldo Base</label><input id="c-sb" type="number" min="0"></div>'+
    '<div class="fld"><label>Horario</label><input id="c-horario" placeholder="Ej: 09:00-18:00"></div>'+
    '<div class="fld"><label>Rol ERP</label><select id="c-rol"><option value="">Sin rol</option></select></div>'+
    '</div>'+
    '<h4 style="font-size:14px;font-weight:600;color:#1e293b;margin:12px 0 8px;border-top:1px solid #e2e8f0;padding-top:12px;"><i class="fas fa-key"></i> Credenciales de Acceso</h4>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Correo acceso</label><input id="c-credc" type="email" placeholder="usuario@empresa.cl"></div>'+
    '<div class="fld"><label>Contraseña</label><input id="c-credp" type="password" placeholder="Mínimo 6 caracteres"></div>'+
    '</div>'+
    '<div class="mcb"><button class="btn-g" onclick="$(\'cm\').classList.remove(\'show\')">Cancelar</button><button class="btn-p" onclick="saveCreate()"><i class="fas fa-save"></i> Guardar</button></div>';
  m.classList.add('show');
  m.onclick=function(e){if(e.target===m)m.classList.remove('show');};
  setTimeout(function(){$('c-nombres').focus();},100);
  // Load roles into dropdown
  var xhr=new XMLHttpRequest();
  xhr.open('POST','../assets/api/core.php',true);
  xhr.setRequestHeader('Content-Type','application/json');
  xhr.onload=function(){
    try{var roles=JSON.parse(xhr.responseText); var sel=$('c-rol'); if(sel) roles.forEach(function(r){sel.innerHTML+='<option value="'+r.id_rol+'">'+esc(r.nombre)+'</option>';});}catch(e){}
  };
  xhr.send(JSON.stringify({accion:'roles'}));
}

function saveCreate(){
  var d={
    accion:'crear',
    rut:$('c-rut').value,
    nombres:$('c-nombres').value,
    apellidos:$('c-apellidos').value,
    fecha_nacimiento:$('c-fn').value,
    sexo:$('c-sexo').value,
    estado_civil:$('c-ec').value,
    correo_personal:$('c-mailp').value,
    correo_corporativo:$('c-mailc').value,
    telefono:$('c-tel').value,
    celular:$('c-cel').value,
    direccion:$('c-dir').value,
    comuna:$('c-comuna').value,
    ciudad:$('c-ciudad').value,
    cargo:$('c-cargo').value,
    departamento:$('c-depto').value,
    sucursal:$('c-suc').value,
    centro_costo:$('c-cc').value,
    jefe_directo:$('c-jefe').value,
    fecha_ingreso:$('c-fi').value,
    tipo_contrato:$('c-tc').value,
    modalidad:$('c-modal').value,
    sueldo_base:parseInt($('c-sb').value)||0,
    horario:$('c-horario').value,
    id_rol:parseInt($('c-rol').value)||0
  };
  if(!d.nombres||!d.apellidos){toast('Nombres y apellidos son obligatorios','err');return;}
  var xhr=new XMLHttpRequest();
  xhr.open('POST','../assets/api/empleados.php',true);
  xhr.setRequestHeader('Content-Type','application/json');
  xhr.onload=function(){
    if(xhr.status===201){
      var emp=JSON.parse(xhr.responseText);
      toast('<i class="fas fa-check-circle"></i> Empleado creado');
      // If credentials provided, create user account
      var credc=$('c-credc').value.trim();
      var credp=$('c-credp').value;
      if(credc&&credp){
        var xhr2=new XMLHttpRequest();
        xhr2.open('POST','../assets/api/empleados.php',true);
        xhr2.setRequestHeader('Content-Type','application/json');
        xhr2.onload=function(){
          if(xhr2.status===201) toast('Credenciales creadas');
          else try{var e=JSON.parse(xhr2.responseText);toast(e.error||'Error credenciales','err');}catch(e2){}
          loadEmp();
        };
        xhr2.send(JSON.stringify({accion:'crear_credenciales',id_empleado:emp.id_empleado||emp.id,correo:credc,password:credp,id_rol:d.id_rol}));
      }else{loadEmp();}
      $('cm').classList.remove('show');
    }else{try{var e=JSON.parse(xhr.responseText);toast(e.error||'Error','err');}catch(e2){toast('Error','err');}}
  };
  xhr.send(JSON.stringify(d));
}

/* ── Edit ── */
function showEdit(id){
  var xhr=new XMLHttpRequest();
  xhr.open('GET','../assets/api/empleados.php?id='+id,true);
  xhr.onload=function(){
    if(xhr.status!==200)return;
    var p=JSON.parse(xhr.responseText);
    var m=$('cm'),b=$('cbody');
    b.innerHTML=
      '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-user-edit" style="color:#4f46e5;"></i> Editar Empleado</h3>'+
      '<div class="gr2">'+
      '<div class="fld"><label>RUT</label><input id="c-rut" value="'+esc(p.rut||'')+'"></div>'+
      '<div class="fld"><label>Nombres *</label><input id="c-nombres" value="'+esc(p.nombres)+'"></div>'+
      '<div class="fld"><label>Apellidos *</label><input id="c-apellidos" value="'+esc(p.apellidos)+'"></div>'+
      '<div class="fld"><label>Fecha Nac.</label><input id="c-fn" type="date" value="'+bd(p.fecha_nacimiento)+'"></div>'+
      '<div class="fld"><label>Sexo</label><select id="c-sexo"><option value="">Seleccionar</option><option value="M"'+(p.sexo==='M'?' selected':'')+'>Masculino</option><option value="F"'+(p.sexo==='F'?' selected':'')+'>Femenino</option><option value="Otro"'+(p.sexo==='Otro'?' selected':'')+'>Otro</option></select></div>'+
      '<div class="fld"><label>Estado Civil</label><select id="c-ec"><option value="">Seleccionar</option><option value="Soltero/a"'+(p.estado_civil==='Soltero/a'?' selected':'')+'>Soltero/a</option><option value="Casado/a"'+(p.estado_civil==='Casado/a'?' selected':'')+'>Casado/a</option><option value="Divorciado/a"'+(p.estado_civil==='Divorciado/a'?' selected':'')+'>Divorciado/a</option><option value="Viudo/a"'+(p.estado_civil==='Viudo/a'?' selected':'')+'>Viudo/a</option></select></div>'+
      '<div class="fld"><label>Correo Personal</label><input id="c-mailp" value="'+esc(p.correo_personal||'')+'"></div>'+
      '<div class="fld"><label>Correo Corp.</label><input id="c-mailc" value="'+esc(p.correo_corporativo||'')+'"></div>'+
      '<div class="fld"><label>Teléfono</label><input id="c-tel" value="'+esc(p.telefono||'')+'"></div>'+
      '<div class="fld"><label>Celular</label><input id="c-cel" value="'+esc(p.celular||'')+'"></div>'+
      '<div class="fld" style="grid-column:1/-1;"><label>Dirección</label><input id="c-dir" value="'+esc(p.direccion||'')+'"></div>'+
      '<div class="fld"><label>Comuna</label><input id="c-comuna" value="'+esc(p.comuna||'')+'"></div>'+
      '<div class="fld"><label>Ciudad</label><input id="c-ciudad" value="'+esc(p.ciudad||'')+'"></div>'+
      '</div>'+
      '<h4 style="font-size:14px;font-weight:600;color:#1e293b;margin:12px 0 8px;border-top:1px solid #e2e8f0;padding-top:12px;"><i class="fas fa-briefcase"></i> Información Laboral</h4>'+
      '<div class="gr2">'+
      '<div class="fld"><label>Cargo</label><input id="c-cargo" value="'+esc(p.cargo||'')+'"></div>'+
      '<div class="fld"><label>Departamento</label><input id="c-depto" value="'+esc(p.departamento||'')+'"></div>'+
      '<div class="fld"><label>Sucursal</label><input id="c-suc" value="'+esc(p.sucursal||'')+'"></div>'+
      '<div class="fld"><label>Centro Costo</label><input id="c-cc" value="'+esc(p.centro_costo||'')+'"></div>'+
      '<div class="fld"><label>Jefe Directo</label><input id="c-jefe" value="'+esc(p.jefe_directo||'')+'"></div>'+
      '<div class="fld"><label>Fecha Ingreso</label><input id="c-fi" type="date" value="'+bd(p.fecha_ingreso)+'"></div>'+
      '<div class="fld"><label>Tipo Contrato</label><select id="c-tc"><option value="">Seleccionar</option><option value="Indefinido"'+(p.tipo_contrato==='Indefinido'?' selected':'')+'>Indefinido</option><option value="Plazo Fijo"'+(p.tipo_contrato==='Plazo Fijo'?' selected':'')+'>Plazo Fijo</option><option value="Honorarios"'+(p.tipo_contrato==='Honorarios'?' selected':'')+'>Honorarios</option><option value="Part-time"'+(p.tipo_contrato==='Part-time'?' selected':'')+'>Part-time</option><option value="Práctica"'+(p.tipo_contrato==='Práctica'?' selected':'')+'>Práctica</option></select></div>'+
      '<div class="fld"><label>Modalidad</label><select id="c-modal"><option value="Presencial"'+(p.modalidad==='Presencial'?' selected':'')+'>Presencial</option><option value="Remoto"'+(p.modalidad==='Remoto'?' selected':'')+'>Remoto</option><option value="Híbrido"'+(p.modalidad==='Híbrido'?' selected':'')+'>Híbrido</option></select></div>'+
      '<div class="fld"><label>Sueldo Base</label><input id="c-sb" type="number" value="'+(p.sueldo_base||0)+'"></div>'+
      '<div class="fld"><label>Horario</label><input id="c-horario" value="'+esc(p.horario||'')+'"></div>'+
      '</div>'+
      '<div class="mcb"><button class="btn-g" onclick="$(\'cm\').classList.remove(\'show\')">Cancelar</button><button class="btn-p" onclick="saveEdit('+id+')"><i class="fas fa-save"></i> Guardar</button></div>';
    m.classList.add('show');
    m.onclick=function(e){if(e.target===m)m.classList.remove('show');};
  };
  xhr.send();
}

function saveEdit(id){
  var d={
    accion:'editar',id_empleado:id,
    rut:$('c-rut').value,nombres:$('c-nombres').value,apellidos:$('c-apellidos').value,
    fecha_nacimiento:$('c-fn').value,sexo:$('c-sexo').value,estado_civil:$('c-ec').value,
    correo_personal:$('c-mailp').value,correo_corporativo:$('c-mailc').value,
    telefono:$('c-tel').value,celular:$('c-cel').value,
    direccion:$('c-dir').value,comuna:$('c-comuna').value,ciudad:$('c-ciudad').value,
    cargo:$('c-cargo').value,departamento:$('c-depto').value,sucursal:$('c-suc').value,
    centro_costo:$('c-cc').value,jefe_directo:$('c-jefe').value,
    fecha_ingreso:$('c-fi').value,tipo_contrato:$('c-tc').value,modalidad:$('c-modal').value,
    sueldo_base:parseInt($('c-sb').value)||0,horario:$('c-horario').value
  };
  var xhr=new XMLHttpRequest();
  xhr.open('POST','../assets/api/empleados.php',true);
  xhr.setRequestHeader('Content-Type','application/json');
  xhr.onload=function(){
    if(xhr.status===200){toast('Empleado actualizado');$('cm').classList.remove('show');loadEmp();}
    else{try{var e=JSON.parse(xhr.responseText);toast(e.error||'Error','err');}catch(e2){toast('Error','err');}}
  };
  xhr.send(JSON.stringify(d));
}

function cambiarEstado(id,estado){
  var xhr=new XMLHttpRequest();
  xhr.open('POST','../assets/api/empleados.php',true);
  xhr.setRequestHeader('Content-Type','application/json');
  xhr.onload=function(){
    if(xhr.status===200){toast('Estado actualizado');$('pm').classList.remove('show');loadEmp();}
    else{toast('Error','err');}
  };
  xhr.send(JSON.stringify({accion:'cambiar_estado',id_empleado:id,estado:estado}));
}

function eliminarEmpleado(id, nombre) {
  if (!confirm('¿Eliminar definitivamente a ' + nombre + '?\n\nEsta acción no se puede deshacer.')) return;
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/empleados.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status === 200) { toast('Empleado eliminado'); loadEmp(); }
    else { try { var e = JSON.parse(xhr.responseText); toast(e.error || 'Error', 'err'); } catch(ex) { toast('Error', 'err'); } }
  };
  xhr.send(JSON.stringify({accion: 'empleado_eliminar', id_empleado: id}));
}

/* ═══════════════════════════════════════════
   PROFILE
   ═══════════════════════════════════════════ */
function showProfile(id){
  _profileId=id;
  var m=$('pm'),b=$('pbody');
  b.innerHTML='<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#4f46e5;"></i></div>';
  m.classList.add('show');
  m.onclick=function(e){if(e.target===m)m.classList.remove('show');};
  var xhr=new XMLHttpRequest();
  xhr.open('GET','../assets/api/empleados.php?id='+id,true);
  xhr.onload=function(){
    if(xhr.status!==200){b.innerHTML='<p>Error</p>';return;}
    var p=JSON.parse(xhr.responseText);
    renderProfile(p);
  };
  xhr.send();
}

function renderProfile(p){
  var b=$('pbody');
  var bc='badge-'+(p.estado||'ACTIVO');
  var ini=esc(p.nombres).charAt(0)+esc(p.apellidos).charAt(0);
  b.innerHTML=
    '<div class="pinfo">'+
    '<div class="ava">'+ini+'</div>'+
    '<div style="flex:1;"><div style="display:flex;justify-content:space-between;align-items:flex-start;">'+
    '<div><h3 style="font-size:18px;font-weight:700;color:#1e293b;">'+esc(p.nombres)+' '+esc(p.apellidos)+'</h3>'+
    '<div style="font-size:13px;color:#64748b;">'+h(p.codigo)+' · '+h(p.rut)+' · '+h(p.cargo)+' · '+h(p.departamento)+'</div></div>'+
    '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;"><span class="badge '+bc+'" style="font-size:12px;padding:4px 14px;">'+p.estado+'</span>'+
    '<button class="bi green" onclick="showVincularUsuario('+p.id_empleado+')" title="Vincular usuario existente"><i class="fas fa-link"></i> Vincular</button>'+
    '<button class="bi green" onclick="showCrearCredenciales('+p.id_empleado+')" title="Crear credenciales de acceso"><i class="fas fa-key"></i> Credenciales</button>'+
    '<button class="bi" onclick="showEdit('+p.id_empleado+')" title="Editar"><i class="fas fa-pen"></i></button>'+
    '<button class="bi" onclick="$(\'pm\').classList.remove(\'show\')" title="Cerrar"><i class="fas fa-times"></i></button></div></div>'+
    '<div style="font-size:12px;color:#94a3b8;margin-top:4px;">Ingreso: '+bd(p.fecha_ingreso)+' · '+h(p.tipo_contrato)+' · '+h(p.modalidad)+'</div>'+
    '</div></div>'+

    '<div class="tabs" id="ptabs">'+
    '<div class="tab active" data-tab="general" onclick="switchTab(\'general\')"><i class="fas fa-info-circle"></i> General</div>'+
    '<div class="tab" data-tab="contratos" onclick="switchTab(\'contratos\')"><i class="fas fa-file-signature"></i> Contratos</div>'+
    '<div class="tab" data-tab="documentos" onclick="switchTab(\'documentos\')"><i class="fas fa-folder"></i> Docs</div>'+
    '<div class="tab" data-tab="asistencia" onclick="switchTab(\'asistencia\')"><i class="fas fa-clock"></i> Asistencia</div>'+
    '<div class="tab" data-tab="vacaciones" onclick="switchTab(\'vacaciones\')"><i class="fas fa-plane"></i> Vacaciones</div>'+
    '<div class="tab" data-tab="permisos" onclick="switchTab(\'permisos\')"><i class="fas fa-user-clock"></i> Permisos</div>'+
    '<div class="tab" data-tab="licencias" onclick="switchTab(\'licencias\')"><i class="fas fa-notes-medical"></i> Licencias</div>'+
    '<div class="tab" data-tab="horas_extra" onclick="switchTab(\'horas_extra\')"><i class="fas fa-clock"></i> Hrs.Extra</div>'+
    '<div class="tab" data-tab="remuneraciones" onclick="switchTab(\'remuneraciones\')"><i class="fas fa-money-bill"></i> Rem.</div>'+
    '<div class="tab" data-tab="beneficios" onclick="switchTab(\'beneficios\')"><i class="fas fa-gift"></i> Beneficios</div>'+
    '<div class="tab" data-tab="capacitaciones" onclick="switchTab(\'capacitaciones\')"><i class="fas fa-graduation-cap"></i> Capac.</div>'+
    '<div class="tab" data-tab="evaluaciones" onclick="switchTab(\'evaluaciones\')"><i class="fas fa-star"></i> Eval.</div>'+
    '<div class="tab" data-tab="activos" onclick="switchTab(\'activos\')"><i class="fas fa-laptop"></i> Activos</div>'+
    '<div class="tab" data-tab="historial" onclick="switchTab(\'historial\')"><i class="fas fa-history"></i> Historial</div>'+
    '<div class="tab" data-tab="auditoria" onclick="switchTab(\'auditoria\')"><i class="fas fa-shield-alt"></i> Auditoría</div>'+
    '</div>'+

    '<div id="ptab-general" class="tab-pane active">'+generalHtml(p)+'</div>'+
    '<div id="ptab-contratos" class="tab-pane">'+contratosHtml(p)+'</div>'+
    '<div id="ptab-documentos" class="tab-pane">'+documentosHtml(p)+'</div>'+
    '<div id="ptab-asistencia" class="tab-pane">'+asistenciaHtml(p)+'</div>'+
    '<div id="ptab-vacaciones" class="tab-pane">'+vacacionesHtml(p)+'</div>'+
    '<div id="ptab-permisos" class="tab-pane">'+permisosHtml(p)+'</div>'+
    '<div id="ptab-licencias" class="tab-pane">'+licenciasHtml(p)+'</div>'+
    '<div id="ptab-horas_extra" class="tab-pane">'+horasExtraHtml(p)+'</div>'+
    '<div id="ptab-remuneraciones" class="tab-pane">'+remuneracionesHtml(p)+'</div>'+
    '<div id="ptab-beneficios" class="tab-pane">'+beneficiosHtml(p)+'</div>'+
    '<div id="ptab-capacitaciones" class="tab-pane">'+capacitacionesHtml(p)+'</div>'+
    '<div id="ptab-evaluaciones" class="tab-pane">'+evaluacionesHtml(p)+'</div>'+
    '<div id="ptab-activos" class="tab-pane">'+activosHtml(p)+'</div>'+
    '<div id="ptab-historial" class="tab-pane">'+historialHtml(p)+'</div>'+
    '<div id="ptab-auditoria" class="tab-pane">'+auditoriaHtml(p)+'</div>';
}

function switchTab(tab){
  var tabs=document.querySelectorAll('#ptabs .tab');
  for(var i=0;i<tabs.length;i++)tabs[i].classList.remove('active');
  var t=document.querySelector('#ptabs .tab[data-tab="'+tab+'"]');
  if(t)t.classList.add('active');
  var panes=document.querySelectorAll('#pbody .tab-pane');
  for(var j=0;j<panes.length;j++)panes[j].classList.remove('active');
  var pane=$('ptab-'+tab);
  if(pane)pane.classList.add('active');
}

/* ═══════════════════════════════════════════
   TAB: General
   ═══════════════════════════════════════════ */
function generalHtml(p){
  return '<div class="gr3" style="margin-top:4px;">'+
    /* Personal */
    '<div><h5 style="font-size:13px;font-weight:600;color:#4f46e5;margin-bottom:8px;"><i class="fas fa-user"></i> Personal</h5>'+
    '<div class="fld"><label>RUT</label><div style="padding:4px 0;font-weight:500;">'+bd(p.rut)+'</div></div>'+
    '<div class="fld"><label>Fecha Nac.</label><div style="padding:4px 0;font-weight:500;">'+bd(p.fecha_nacimiento)+' <span style="color:#64748b;font-size:12px;">('+(p.edad||'-')+' años)</span></div></div>'+
    '<div class="fld"><label>Sexo</label><div style="padding:4px 0;font-weight:500;">'+bd(p.sexo)+'</div></div>'+
    '<div class="fld"><label>Estado Civil</label><div style="padding:4px 0;font-weight:500;">'+bd(p.estado_civil)+'</div></div>'+
    '<div class="fld"><label>Nacionalidad</label><div style="padding:4px 0;font-weight:500;">'+bd(p.nacionalidad)+'</div></div></div>'+
    /* Contacto */
    '<div><h5 style="font-size:13px;font-weight:600;color:#4f46e5;margin-bottom:8px;"><i class="fas fa-address-card"></i> Contacto</h5>'+
    '<div class="fld"><label>Correo Personal</label><div style="padding:4px 0;font-weight:500;">'+bd(p.correo_personal)+'</div></div>'+
    '<div class="fld"><label>Correo Corp.</label><div style="padding:4px 0;font-weight:500;">'+bd(p.correo_corporativo)+'</div></div>'+
    '<div class="fld"><label>Teléfono</label><div style="padding:4px 0;font-weight:500;">'+bd(p.telefono)+'</div></div>'+
    '<div class="fld"><label>Celular</label><div style="padding:4px 0;font-weight:500;">'+bd(p.celular)+'</div></div>'+
    '<div class="fld"><label>Dirección</label><div style="padding:4px 0;font-weight:500;">'+bd(p.direccion)+', '+bd(p.comuna)+', '+bd(p.ciudad)+'</div></div>'+
    '<div class="fld"><label>Emergencia</label><div style="padding:4px 0;font-weight:500;">'+bd(p.contacto_emergencia_nombre)+' · '+bd(p.contacto_emergencia_telefono)+'</div></div></div>'+
    /* Laboral + Contractual */
    '<div><h5 style="font-size:13px;font-weight:600;color:#4f46e5;margin-bottom:8px;"><i class="fas fa-briefcase"></i> Laboral / Contractual</h5>'+
    '<div class="fld"><label>Cargo</label><div style="padding:4px 0;font-weight:500;">'+bd(p.cargo)+'</div></div>'+
    '<div class="fld"><label>Departamento</label><div style="padding:4px 0;font-weight:500;">'+bd(p.departamento)+'</div></div>'+
    '<div class="fld"><label>Sucursal</label><div style="padding:4px 0;font-weight:500;">'+bd(p.sucursal)+'</div></div>'+
    '<div class="fld"><label>Centro Costo</label><div style="padding:4px 0;font-weight:500;">'+bd(p.centro_costo)+'</div></div>'+
    '<div class="fld"><label>Jefe Directo</label><div style="padding:4px 0;font-weight:500;">'+bd(p.jefe_directo)+'</div></div>'+
    '<div class="fld"><label>Contrato</label><div style="padding:4px 0;font-weight:500;">'+bd(p.tipo_contrato)+' ('+bd(p.modalidad)+')</div></div>'+
    '<div class="fld"><label>Sueldo Base</label><div style="padding:4px 0;font-weight:500;">'+fm(p.sueldo_base)+'</div></div>'+
    '<div class="fld"><label>Horario</label><div style="padding:4px 0;font-weight:500;">'+bd(p.horario)+'</div></div>'+
    '<div class="fld"><label>AFP/Salud</label><div style="padding:4px 0;font-weight:500;">'+bd(p.afp)+' / '+bd(p.salud)+'</div></div>'+
    '<div class="fld"><label>Banco</label><div style="padding:4px 0;font-weight:500;">'+bd(p.banco)+' · '+bd(p.tipo_cuenta)+' '+bd(p.numero_cuenta)+'</div></div>'+
    '</div></div>';
}

/* ═══════════════════════════════════════════
   TAB: Contratos
   ═══════════════════════════════════════════ */
function contratosHtml(p){
  var cs=p.contratos||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddContrato()"><i class="fas fa-plus"></i> Agregar Contrato</button></div>';
  if(!cs.length)h+='<p style="color:#94a3b8;">Sin contratos registrados</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Tipo</th><th>Inicio</th><th>Término</th><th>Sueldo Base</th><th>Asignaciones</th><th>Bonos</th><th>Estado</th><th></th></tr></thead><tbody>';
    for(var i=0;i<cs.length;i++){
      var c=cs[i];
      h+='<tr><td><strong>'+esc(c.tipo||'')+'</strong></td><td>'+bd(c.fecha_inicio)+'</td><td>'+bd(c.fecha_termino)+'</td><td>'+fm(c.sueldo_base)+'</td><td>'+fm(c.asignaciones)+'</td><td>'+fm(c.bonos)+'</td><td><span class="badge badge-'+(c.estado||'ACTIVO')+'">'+c.estado+'</span></td>'+
        '<td><button class="bi red" onclick="delContrato('+c.id_contrato+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddContrato(){
  var pane=$('ptab-contratos');
  if($('contrato-form'))return;
  var f=document.createElement('div');f.id='contrato-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-file-signature" style="color:#4f46e5;"></i> Nuevo Contrato</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Tipo *</label><select id="con-tipo"><option value="Indefinido">Indefinido</option><option value="Plazo Fijo">Plazo Fijo</option><option value="Honorarios">Honorarios</option><option value="Part-time">Part-time</option></select></div>'+
    '<div class="fld"><label>Fecha Inicio</label><input id="con-fi" type="date"></div>'+
    '<div class="fld"><label>Fecha Término</label><input id="con-ft" type="date"></div>'+
    '<div class="fld"><label>Sueldo Base</label><input id="con-sb" type="number" min="0"></div>'+
    '<div class="fld"><label>Asignaciones</label><input id="con-asig" type="number" min="0"></div>'+
    '<div class="fld"><label>Bonos</label><input id="con-bonos" type="number" min="0"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Notas</label><textarea id="con-notas" rows="1"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'contrato-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveContrato()" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
  $('con-tipo').focus();
}

function saveContrato(){
  var d={
    accion:'contrato_crear',id_empleado:_profileId,
    tipo:$('con-tipo').value,fecha_inicio:$('con-fi').value,fecha_termino:$('con-ft').value,
    sueldo_base:parseInt($('con-sb').value)||0,asignaciones:parseInt($('con-asig').value)||0,
    bonos:parseInt($('con-bonos').value)||0,notas:$('con-notas').value
  };
  if(!d.tipo){toast('Tipo es obligatorio','err');return;}
  apiPost(d,function(){toast('Contrato agregado');showProfile(_profileId);});
}

function delContrato(id){
  apiPost({accion:'contrato_eliminar',id_contrato:id},function(){toast('Contrato eliminado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Documentos
   ═══════════════════════════════════════════ */
function documentosHtml(p){
  var ds=p.documentos||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddDocumento()"><i class="fas fa-plus"></i> Agregar Documento</button></div>';
  if(!ds.length)h+='<p style="color:#94a3b8;">Sin documentos</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Nombre</th><th>Tipo</th><th>Emisión</th><th>Vencimiento</th><th>Estado</th><th></th></tr></thead><tbody>';
    for(var i=0;i<ds.length;i++){
      var d=ds[i];
      var exd=d.fecha_vencimiento&&new Date(d.fecha_vencimiento)<new Date()?'VENCIDO':'VIGENTE';
      h+='<tr><td><strong>'+esc(d.nombre)+'</strong></td><td><span class="tag">'+esc(d.tipo||'')+'</span></td><td>'+bd(d.fecha_emision)+'</td><td>'+bd(d.fecha_vencimiento)+'</td><td><span class="badge badge-'+(d.estado||exd)+'">'+(d.estado||exd)+'</span></td>'+
        '<td><button class="bi red" onclick="delDocumento('+d.id_documento+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddDocumento(){
  var pane=$('ptab-documentos');if($('doc-form'))return;
  var f=document.createElement('div');f.id='doc-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-folder" style="color:#4f46e5;"></i> Nuevo Documento</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Nombre *</label><input id="doc-nombre"></div>'+
    '<div class="fld"><label>Tipo</label><select id="doc-tipo"><option value="Contrato">Contrato</option><option value="Anexo">Anexo</option><option value="Currículum">Currículum</option><option value="Cédula">Cédula</option><option value="Certificado">Certificado</option><option value="Título">Título</option><option value="Licencia">Licencia</option><option value="AFP">AFP</option><option value="Salud">Salud</option><option value="Antecedentes">Antecedentes</option><option value="Finiquito">Finiquito</option><option value="Otro">Otro</option></select></div>'+
    '<div class="fld"><label>Fecha Emisión</label><input id="doc-fe" type="date"></div>'+
    '<div class="fld"><label>Fecha Vencimiento</label><input id="doc-fv" type="date"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Notas</label><textarea id="doc-notas" rows="1"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'doc-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveDocumento()" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
  $('doc-nombre').focus();
}

function saveDocumento(){
  var d={
    accion:'documento_crear',id_empleado:_profileId,
    nombre:$('doc-nombre').value,tipo:$('doc-tipo').value,
    fecha_emision:$('doc-fe').value,fecha_vencimiento:$('doc-fv').value,
    notas:$('doc-notas').value
  };
  if(!d.nombre){toast('Nombre obligatorio','err');return;}
  apiPost(d,function(){toast('Documento agregado');showProfile(_profileId);});
}

function delDocumento(id){
  apiPost({accion:'documento_eliminar',id_documento:id},function(){toast('Documento eliminado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Asistencia
   ═══════════════════════════════════════════ */
function asistenciaHtml(p){
  var as=p.asistencias||[];
  var h='<div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;"><button class="btn btn-primary btn-sm" onclick="showAddAsistencia()"><i class="fas fa-plus"></i> Registrar</button></div>';
  if(!as.length)h+='<p style="color:#94a3b8;">Sin registros de asistencia</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Fecha</th><th>Entrada</th><th>Salida</th><th>Colación</th><th>Hrs.Trab.</th><th>Hrs.Extra</th><th>Retraso</th><th>Tipo</th><th></th></tr></thead><tbody>';
    for(var i=0;i<as.length;i++){
      var a=as[i];
      h+='<tr><td>'+bd(a.fecha)+'</td><td>'+bd(a.entrada)+'</td><td>'+bd(a.salida)+'</td><td>'+bd(a.colacion)+'</td><td>'+(a.horas_trabajadas||'-')+'</td><td>'+(a.horas_extra||'-')+'</td><td>'+(a.retraso?+a.retraso+'min':'0')+'</td><td><span class="tag">'+esc(a.tipo||'NORMAL')+'</span></td>'+
        '<td><button class="bi red" onclick="delAsistencia('+a.id_asistencia+')"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddAsistencia(){
  var pane=$('ptab-asistencia');if($('asist-form'))return;
  var f=document.createElement('div');f.id='asist-form';f.className='inline-form';
  var hoy=new Date().toISOString().slice(0,10);
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-clock" style="color:#4f46e5;"></i> Registrar Asistencia</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Fecha *</label><input id="as-fecha" type="date" value="'+hoy+'"></div>'+
    '<div class="fld"><label>Entrada</label><input id="as-entrada" type="time"></div>'+
    '<div class="fld"><label>Salida</label><input id="as-salida" type="time"></div>'+
    '<div class="fld"><label>Colación</label><input id="as-colacion" type="time"></div>'+
    '<div class="fld"><label>Horas Trabajadas</label><input id="as-ht" type="number" step="0.5" min="0"></div>'+
    '<div class="fld"><label>Horas Extra</label><input id="as-he" type="number" step="0.5" min="0"></div>'+
    '<div class="fld"><label>Retraso (min)</label><input id="as-retraso" type="number" min="0"></div>'+
    '<div class="fld"><label>Tipo</label><select id="as-tipo"><option value="NORMAL">Normal</option><option value="PERMISO">Permiso</option><option value="LICENCIA">Licencia</option><option value="VACACIONES">Vacaciones</option><option value="AUSENTE">Ausente</option></select></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Observaciones</label><textarea id="as-obs" rows="1"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'asist-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveAsistencia()" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveAsistencia(){
  var d={
    accion:'asistencia_crear',id_empleado:_profileId,
    fecha:$('as-fecha').value,entrada:$('as-entrada').value,salida:$('as-salida').value,
    colacion:$('as-colacion').value,horas_trabajadas:parseFloat($('as-ht').value)||null,
    horas_extra:parseFloat($('as-he').value)||null,retraso:parseInt($('as-retraso').value)||0,
    tipo:$('as-tipo').value,observaciones:$('as-obs').value
  };
  apiPost(d,function(){toast('Asistencia registrada');showProfile(_profileId);});
}

function delAsistencia(id){
  apiPost({accion:'asistencia_eliminar',id_asistencia:id},function(){toast('Registro eliminado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Vacaciones
   ═══════════════════════════════════════════ */
function vacacionesHtml(p){
  var vs=p.vacaciones||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddVacacion()"><i class="fas fa-plus"></i> Solicitar Vacaciones</button></div>';
  if(!vs.length)h+='<p style="color:#94a3b8;">Sin solicitudes de vacaciones</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Inicio</th><th>Fin</th><th>Días</th><th>Tipo</th><th>Estado</th><th>Comentarios</th><th></th></tr></thead><tbody>';
    for(var i=0;i<vs.length;i++){
      var v=vs[i];
      h+='<tr><td>'+bd(v.fecha_inicio)+'</td><td>'+bd(v.fecha_fin)+'</td><td><strong>'+v.dias+'</strong></td><td>'+esc(v.tipo||'PROGRESIVAS')+'</td><td><span class="badge badge-'+(v.estado||'PENDIENTE')+'">'+v.estado+'</span></td><td style="font-size:12px;color:#64748b;">'+esc(v.comentarios||'')+'</td>'+
        '<td>'+(v.estado==='PENDIENTE'?'<button class="bi green" onclick="aprobarVacacion('+v.id_vacacion+')" title="Aprobar"><i class="fas fa-check"></i></button>':'')+
        '<button class="bi red" onclick="delVacacion('+v.id_vacacion+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddVacacion(){
  var pane=$('ptab-vacaciones');if($('vac-form'))return;
  var f=document.createElement('div');f.id='vac-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-plane" style="color:#4f46e5;"></i> Solicitar Vacaciones</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Fecha Inicio *</label><input id="vac-fi" type="date"></div>'+
    '<div class="fld"><label>Fecha Fin *</label><input id="vac-ff" type="date"></div>'+
    '<div class="fld"><label>Días *</label><input id="vac-dias" type="number" min="1"></div>'+
    '<div class="fld"><label>Tipo</label><select id="vac-tipo"><option value="PROGRESIVAS">Progresivas</option><option value="LEGALES">Legales</option><option value="ADELANTADAS">Adelantadas</option><option value="PENDIENTES">Pendientes</option></select></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Comentarios</label><textarea id="vac-com" rows="1"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'vac-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveVacacion()" style="flex:1;"><i class="fas fa-save"></i> Solicitar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveVacacion(){
  var d={
    accion:'vacacion_crear',id_empleado:_profileId,
    fecha_inicio:$('vac-fi').value,fecha_fin:$('vac-ff').value,
    dias:parseInt($('vac-dias').value),tipo:$('vac-tipo').value,comentarios:$('vac-com').value
  };
  if(!d.fecha_inicio||!d.fecha_fin||!d.dias){toast('Todos los campos son obligatorios','err');return;}
  apiPost(d,function(){toast('Vacaciones solicitadas');showProfile(_profileId);});
}

function aprobarVacacion(id){
  apiPost({accion:'vacacion_aprobar',id_vacacion:id,estado:'APROBADA'},function(){toast('Vacaciones aprobadas');showProfile(_profileId);});
}

function delVacacion(id){
  apiPost({accion:'vacacion_eliminar',id_vacacion:id},function(){toast('Solicitud eliminada');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Permisos
   ═══════════════════════════════════════════ */
function permisosHtml(p){
  var ps=p.permisos||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddPermiso()"><i class="fas fa-plus"></i> Solicitar Permiso</button></div>';
  if(!ps.length)h+='<p style="color:#94a3b8;">Sin permisos registrados</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Tipo</th><th>Inicio</th><th>Fin</th><th>Horas</th><th>Motivo</th><th>Estado</th><th></th></tr></thead><tbody>';
    for(var i=0;i<ps.length;i++){
      var p2=ps[i];
      h+='<tr><td><span class="tag">'+esc(p2.tipo||'')+'</span></td><td>'+bd(p2.fecha_inicio)+'</td><td>'+bd(p2.fecha_fin)+'</td><td>'+(p2.horas||'-')+'</td><td style="font-size:12px;color:#64748b;max-width:150px;overflow:hidden;text-overflow:ellipsis;">'+esc(p2.motivo||'')+'</td><td><span class="badge badge-'+(p2.estado||'PENDIENTE')+'">'+p2.estado+'</span></td>'+
        '<td>'+(p2.estado==='PENDIENTE'?'<button class="bi green" onclick="aprobarPermiso('+p2.id_permiso+')" title="Aprobar"><i class="fas fa-check"></i></button>':'')+
        '<button class="bi red" onclick="delPermiso('+p2.id_permiso+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddPermiso(){
  var pane=$('ptab-permisos');if($('perm-form'))return;
  var f=document.createElement('div');f.id='perm-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-user-clock" style="color:#4f46e5;"></i> Solicitar Permiso</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Tipo *</label><select id="perm-tipo"><option value="Administrativo">Administrativo</option><option value="Médico">Médico</option><option value="Sin goce">Sin goce</option><option value="Especial">Especial</option><option value="Legal">Legal</option></select></div>'+
    '<div class="fld"><label>Fecha Inicio *</label><input id="perm-fi" type="date"></div>'+
    '<div class="fld"><label>Fecha Fin *</label><input id="perm-ff" type="date"></div>'+
    '<div class="fld"><label>Horas</label><input id="perm-hrs" type="number" min="0"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Motivo</label><textarea id="perm-motivo" rows="2"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'perm-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="savePermiso()" style="flex:1;"><i class="fas fa-save"></i> Solicitar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function savePermiso(){
  var d={
    accion:'permiso_crear',id_empleado:_profileId,
    tipo:$('perm-tipo').value,fecha_inicio:$('perm-fi').value,fecha_fin:$('perm-ff').value,
    horas:parseInt($('perm-hrs').value)||0,motivo:$('perm-motivo').value
  };
  if(!d.fecha_inicio||!d.fecha_fin){toast('Fechas requeridas','err');return;}
  apiPost(d,function(){toast('Permiso solicitado');showProfile(_profileId);});
}

function aprobarPermiso(id){
  apiPost({accion:'permiso_aprobar',id_permiso:id,estado:'APROBADO'},function(){toast('Permiso aprobado');showProfile(_profileId);});
}

function delPermiso(id){
  apiPost({accion:'permiso_eliminar',id_permiso:id},function(){toast('Permiso eliminado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Licencias
   ═══════════════════════════════════════════ */
function licenciasHtml(p){
  var ls=p.licencias||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddLicencia()"><i class="fas fa-plus"></i> Registrar Licencia</button></div>';
  if(!ls.length)h+='<p style="color:#94a3b8;">Sin licencias registradas</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Tipo</th><th>Inicio</th><th>Fin</th><th>Folio</th><th>Entidad</th><th>Subsidio</th><th>Estado</th><th></th></tr></thead><tbody>';
    for(var i=0;i<ls.length;i++){
      var l=ls[i];
      h+='<tr><td><strong>'+esc(l.tipo||'')+'</strong></td><td>'+bd(l.fecha_inicio)+'</td><td>'+bd(l.fecha_fin)+'</td><td>'+bd(l.folio)+'</td><td>'+bd(l.entidad_emisora)+'</td><td>'+fm(l.subsidio)+'</td><td><span class="badge badge-'+(l.estado||'ACTIVA')+'">'+l.estado+'</span></td>'+
        '<td><button class="bi red" onclick="delLicencia('+l.id_licencia+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddLicencia(){
  var pane=$('ptab-licencias');if($('lic-form'))return;
  var f=document.createElement('div');f.id='lic-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-notes-medical" style="color:#4f46e5;"></i> Registrar Licencia Médica</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Tipo *</label><input id="lic-tipo" placeholder="Ej: Enfermedad común, Maternidad"></div>'+
    '<div class="fld"><label>Folio</label><input id="lic-folio"></div>'+
    '<div class="fld"><label>Fecha Inicio *</label><input id="lic-fi" type="date"></div>'+
    '<div class="fld"><label>Fecha Fin *</label><input id="lic-ff" type="date"></div>'+
    '<div class="fld"><label>Entidad Emisora</label><input id="lic-entidad"></div>'+
    '<div class="fld"><label>Subsidio</label><input id="lic-subsidio" type="number" min="0"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Diagnóstico</label><textarea id="lic-diag" rows="1"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'lic-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveLicencia()" style="flex:1;"><i class="fas fa-save"></i> Registrar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveLicencia(){
  var d={
    accion:'licencia_crear',id_empleado:_profileId,
    tipo:$('lic-tipo').value,fecha_inicio:$('lic-fi').value,fecha_fin:$('lic-ff').value,
    folio:$('lic-folio').value,entidad_emisora:$('lic-entidad').value,
    diagnostico:$('lic-diag').value,subsidio:parseInt($('lic-subsidio').value)||0
  };
  if(!d.tipo||!d.fecha_inicio||!d.fecha_fin){toast('Datos obligatorios incompletos','err');return;}
  apiPost(d,function(){toast('Licencia registrada');showProfile(_profileId);});
}

function delLicencia(id){
  apiPost({accion:'licencia_eliminar',id_licencia:id},function(){toast('Licencia eliminada');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Horas Extras
   ═══════════════════════════════════════════ */
function horasExtraHtml(p){
  var hs=p.horas_extras||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddHoraExtra()"><i class="fas fa-plus"></i> Registrar Hora Extra</button></div>';
  if(!hs.length)h+='<p style="color:#94a3b8;">Sin horas extras registradas</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Fecha</th><th>Cantidad</th><th>Motivo</th><th>Estado</th><th>Pago</th><th></th></tr></thead><tbody>';
    for(var i=0;i<hs.length;i++){
      var hx=hs[i];
      h+='<tr><td>'+bd(hx.fecha)+'</td><td><strong>'+hx.cantidad+' hrs</strong></td><td style="font-size:12px;color:#64748b;">'+esc(hx.motivo||'')+'</td><td><span class="badge badge-'+(hx.estado||'PENDIENTE')+'">'+hx.estado+'</span></td><td>'+fm(hx.pago)+'</td>'+
        '<td>'+(hx.estado==='PENDIENTE'?'<button class="bi green" onclick="aprobarHoraExtra('+hx.id_hora_extra+')" title="Aprobar"><i class="fas fa-check"></i></button>':'')+
        '<button class="bi red" onclick="delHoraExtra('+hx.id_hora_extra+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddHoraExtra(){
  var pane=$('ptab-horas_extra');if($('he-form'))return;
  var f=document.createElement('div');f.id='he-form';f.className='inline-form';
  var hoy=new Date().toISOString().slice(0,10);
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-clock" style="color:#4f46e5;"></i> Registrar Hora Extra</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Fecha *</label><input id="he-fecha" type="date" value="'+hoy+'"></div>'+
    '<div class="fld"><label>Cantidad (hrs) *</label><input id="he-cant" type="number" step="0.5" min="0.5"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Motivo</label><textarea id="he-motivo" rows="2"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'he-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveHoraExtra()" style="flex:1;"><i class="fas fa-save"></i> Registrar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveHoraExtra(){
  var d={
    accion:'hora_extra_crear',id_empleado:_profileId,
    fecha:$('he-fecha').value,cantidad:parseFloat($('he-cant').value),motivo:$('he-motivo').value
  };
  if(!d.cantidad||!d.fecha){toast('Fecha y cantidad requeridas','err');return;}
  apiPost(d,function(){toast('Hora extra registrada');showProfile(_profileId);});
}

function aprobarHoraExtra(id){
  apiPost({accion:'hora_extra_aprobar',id_hora_extra:id,estado:'APROBADO',pago:0,compensacion:false},
    function(){toast('Hora extra aprobada');showProfile(_profileId);});
}

function delHoraExtra(id){
  apiPost({accion:'hora_extra_eliminar',id_hora_extra:id},function(){toast('Registro eliminado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Remuneraciones
   ═══════════════════════════════════════════ */
function remuneracionesHtml(p){
  var rs=p.remuneraciones||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddRemuneracion()"><i class="fas fa-plus"></i> Agregar Remuneración</button></div>';
  if(!rs.length)h+='<p style="color:#94a3b8;">Sin remuneraciones registradas</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Período</th><th>S.Base</th><th>Bonific.</th><th>Comisiones</th><th>HE</th><th>Desctos</th><th>Líquido</th><th>Pagado</th><th></th></tr></thead><tbody>';
    for(var i=0;i<rs.length;i++){
      var r=rs[i];
      h+='<tr><td><strong>'+esc(r.periodo)+'</strong></td><td>'+fm(r.sueldo_base)+'</td><td>'+fm(r.bonificaciones)+'</td><td>'+fm(r.comisiones)+'</td><td>'+fm(r.horas_extra)+'</td><td>'+fm(r.descuentos)+'</td><td><strong>'+fm(r.liquido)+'</strong></td><td>'+(r.pagado?'<span class="badge badge-ACTIVO">Sí</span>':'<span class="badge badge-INACTIVO">No</span>')+'</td>'+
        '<td><button class="bi red" onclick="delRemuneracion('+r.id_remuneracion+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddRemuneracion(){
  var pane=$('ptab-remuneraciones');if($('rem-form'))return;
  var f=document.createElement('div');f.id='rem-form';f.className='inline-form';
  var mes=new Date().toISOString().slice(0,7);
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-money-bill" style="color:#4f46e5;"></i> Agregar Remuneración</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Período *</label><input id="rem-periodo" type="month" value="'+mes+'"></div>'+
    '<div class="fld"><label>Sueldo Base</label><input id="rem-sb" type="number" min="0"></div>'+
    '<div class="fld"><label>Bonificaciones</label><input id="rem-bonif" type="number" min="0"></div>'+
    '<div class="fld"><label>Comisiones</label><input id="rem-comis" type="number" min="0"></div>'+
    '<div class="fld"><label>Horas Extra</label><input id="rem-he" type="number" min="0"></div>'+
    '<div class="fld"><label>Descuentos</label><input id="rem-dctos" type="number" min="0"></div>'+
    '<div class="fld"><label>Anticipos</label><input id="rem-ant" type="number" min="0"></div>'+
    '<div class="fld"><label>Líquido (auto)</label><input id="rem-liq" type="number" min="0" readonly style="background:#f1f5f9;"></div>'+
    '<div class="fld" style="grid-column:1/-1;display:flex;align-items:center;gap:8px;"><input type="checkbox" id="rem-pagado"> <label style="margin:0;">Pagado</label></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'rem-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveRemuneracion()" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
  // Auto-calculate liquid
  $('rem-sb').addEventListener('input',calcLiq);
  $('rem-bonif').addEventListener('input',calcLiq);
  $('rem-comis').addEventListener('input',calcLiq);
  $('rem-he').addEventListener('input',calcLiq);
  $('rem-dctos').addEventListener('input',calcLiq);
  $('rem-ant').addEventListener('input',calcLiq);
}

function calcLiq(){
  var sb=parseInt($('rem-sb').value)||0;
  var bn=parseInt($('rem-bonif').value)||0;
  var cm=parseInt($('rem-comis').value)||0;
  var he=parseInt($('rem-he').value)||0;
  var dc=parseInt($('rem-dctos').value)||0;
  var an=parseInt($('rem-ant').value)||0;
  $('rem-liq').value=sb+bn+cm+he-dc-an;
}

function saveRemuneracion(){
  calcLiq();
  var d={
    accion:'remuneracion_crear',id_empleado:_profileId,
    periodo:$('rem-periodo').value,sueldo_base:parseInt($('rem-sb').value)||0,
    bonificaciones:parseInt($('rem-bonif').value)||0,comisiones:parseInt($('rem-comis').value)||0,
    horas_extra:parseInt($('rem-he').value)||0,descuentos:parseInt($('rem-dctos').value)||0,
    anticipos:parseInt($('rem-ant').value)||0,liquido:parseInt($('rem-liq').value)||0
  };
  if(!d.periodo){toast('Período requerido','err');return;}
  apiPost(d,function(){toast('Remuneración agregada');showProfile(_profileId);});
}

function delRemuneracion(id){
  apiPost({accion:'remuneracion_eliminar',id_remuneracion:id},function(){toast('Remuneración eliminada');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Beneficios
   ═══════════════════════════════════════════ */
function beneficiosHtml(p){
  var bs=p.beneficios||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddBeneficio()"><i class="fas fa-plus"></i> Agregar Beneficio</button></div>';
  if(!bs.length)h+='<p style="color:#94a3b8;">Sin beneficios registrados</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Tipo</th><th>Descripción</th><th>Monto</th><th>Vigencia</th><th>Estado</th><th></th></tr></thead><tbody>';
    for(var i=0;i<bs.length;i++){
      var b=bs[i];
      h+='<tr><td><span class="tag">'+esc(b.tipo||'')+'</span></td><td>'+esc(b.descripcion||'')+'</td><td>'+fm(b.monto)+'</td><td>'+bd(b.vigencia)+'</td><td><span class="badge badge-'+(b.estado||'ACTIVO')+'">'+b.estado+'</span></td>'+
        '<td><button class="bi red" onclick="delBeneficio('+b.id_beneficio+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddBeneficio(){
  var pane=$('ptab-beneficios');if($('benef-form'))return;
  var f=document.createElement('div');f.id='benef-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-gift" style="color:#4f46e5;"></i> Nuevo Beneficio</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Tipo *</label><select id="ben-tipo"><option value="Seguro">Seguro</option><option value="Caja Compensación">Caja Compensación</option><option value="Bono">Bono</option><option value="Movilización">Movilización</option><option value="Colación">Colación</option><option value="Teletrabajo">Teletrabajo</option><option value="Capacitación">Capacitación</option><option value="Convenio">Convenio</option><option value="Otro">Otro</option></select></div>'+
    '<div class="fld"><label>Monto</label><input id="ben-monto" type="number" min="0"></div>'+
    '<div class="fld"><label>Vigencia</label><input id="ben-vig" type="date"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Descripción</label><textarea id="ben-desc" rows="2"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'benef-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveBeneficio()" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveBeneficio(){
  var d={
    accion:'beneficio_crear',id_empleado:_profileId,
    tipo:$('ben-tipo').value,descripcion:$('ben-desc').value,
    monto:parseInt($('ben-monto').value)||0,vigencia:$('ben-vig').value
  };
  if(!d.tipo){toast('Tipo obligatorio','err');return;}
  apiPost(d,function(){toast('Beneficio agregado');showProfile(_profileId);});
}

function delBeneficio(id){
  apiPost({accion:'beneficio_eliminar',id_beneficio:id},function(){toast('Beneficio eliminado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Capacitaciones
   ═══════════════════════════════════════════ */
function capacitacionesHtml(p){
  var cs=p.capacitaciones||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddCapacitacion()"><i class="fas fa-plus"></i> Registrar Capacitación</button></div>';
  if(!cs.length)h+='<p style="color:#94a3b8;">Sin capacitaciones registradas</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Curso</th><th>Proveedor</th><th>Fecha</th><th>Horas</th><th>Costo</th><th>Estado</th><th>Vencimiento</th><th></th></tr></thead><tbody>';
    for(var i=0;i<cs.length;i++){
      var c=cs[i];
      h+='<tr><td><strong>'+esc(c.curso)+'</strong></td><td>'+esc(c.proveedor||'')+'</td><td>'+bd(c.fecha)+'</td><td>'+(c.horas||'-')+'</td><td>'+fm(c.costo)+'</td><td><span class="badge badge-'+(c.estado||'PENDIENTE')+'">'+c.estado+'</span></td><td>'+bd(c.vencimiento)+'</td>'+
        '<td><button class="bi red" onclick="delCapacitacion('+c.id_capacitacion+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddCapacitacion(){
  var pane=$('ptab-capacitaciones');if($('cap-form'))return;
  var f=document.createElement('div');f.id='cap-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-graduation-cap" style="color:#4f46e5;"></i> Registrar Capacitación</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Curso *</label><input id="cap-curso"></div>'+
    '<div class="fld"><label>Proveedor</label><input id="cap-prov"></div>'+
    '<div class="fld"><label>Fecha</label><input id="cap-fecha" type="date"></div>'+
    '<div class="fld"><label>Horas</label><input id="cap-hrs" type="number" min="0"></div>'+
    '<div class="fld"><label>Costo</label><input id="cap-costo" type="number" min="0"></div>'+
    '<div class="fld"><label>Vencimiento</label><input id="cap-venc" type="date"></div>'+
    '<div class="fld" style="grid-column:1/-1;display:flex;align-items:center;gap:8px;"><input type="checkbox" id="cap-renov"> <label style="margin:0;">Requiere renovación</label></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'cap-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveCapacitacion()" style="flex:1;"><i class="fas fa-save"></i> Registrar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveCapacitacion(){
  var d={
    accion:'capacitacion_crear',id_empleado:_profileId,
    curso:$('cap-curso').value,proveedor:$('cap-prov').value,
    fecha:$('cap-fecha').value,horas:parseInt($('cap-hrs').value)||0,
    costo:parseInt($('cap-costo').value)||0,vencimiento:$('cap-venc').value,
    renovacion:$('cap-renov').checked
  };
  if(!d.curso){toast('Curso obligatorio','err');return;}
  apiPost(d,function(){toast('Capacitación registrada');showProfile(_profileId);});
}

function delCapacitacion(id){
  apiPost({accion:'capacitacion_eliminar',id_capacitacion:id},function(){toast('Capacitación eliminada');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Evaluaciones
   ═══════════════════════════════════════════ */
function evaluacionesHtml(p){
  var es=p.evaluaciones||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddEvaluacion()"><i class="fas fa-plus"></i> Agregar Evaluación</button></div>';
  if(!es.length)h+='<p style="color:#94a3b8;">Sin evaluaciones registradas</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Período</th><th>Fecha</th><th>Comp.</th><th>Obj.</th><th>Prod.</th><th>Eq.</th><th>Punt.</th><th>Resp.</th><th>Cal.</th><th>Total</th><th>Evaluador</th><th></th></tr></thead><tbody>';
    for(var i=0;i<es.length;i++){
      var e2=es[i];
      h+='<tr><td><strong>'+esc(e2.periodo||'')+'</strong></td><td>'+bd(e2.fecha)+'</td>'+
        '<td>'+e2.competencias+'</td><td>'+e2.objetivos+'</td><td>'+e2.productividad+'</td>'+
        '<td>'+e2.trabajo_equipo+'</td><td>'+e2.puntualidad+'</td><td>'+e2.responsabilidad+'</td><td>'+e2.calidad+'</td>'+
        '<td><strong>'+e2.puntaje_total+'</strong></td><td style="font-size:12px;">'+esc(e2.evaluador||'')+'</td>'+
        '<td><button class="bi red" onclick="delEvaluacion('+e2.id_evaluacion+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddEvaluacion(){
  var pane=$('ptab-evaluaciones');if($('eval-form'))return;
  var f=document.createElement('div');f.id='eval-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-star" style="color:#4f46e5;"></i> Nueva Evaluación</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Período</label><input id="eval-periodo" placeholder="Ej: 2026-S1"></div>'+
    '<div class="fld"><label>Fecha</label><input id="eval-fecha" type="date"></div>'+
    '<div class="fld"><label>Competencias (0-10)</label><input id="eval-comp" type="number" min="0" max="10"></div>'+
    '<div class="fld"><label>Objetivos (0-10)</label><input id="eval-obj" type="number" min="0" max="10"></div>'+
    '<div class="fld"><label>Productividad (0-10)</label><input id="eval-prod" type="number" min="0" max="10"></div>'+
    '<div class="fld"><label>Trabajo Equipo (0-10)</label><input id="eval-teq" type="number" min="0" max="10"></div>'+
    '<div class="fld"><label>Puntualidad (0-10)</label><input id="eval-punt" type="number" min="0" max="10"></div>'+
    '<div class="fld"><label>Responsabilidad (0-10)</label><input id="eval-resp" type="number" min="0" max="10"></div>'+
    '<div class="fld"><label>Calidad (0-10)</label><input id="eval-cal" type="number" min="0" max="10"></div>'+
    '<div class="fld"><label>Evaluador</label><input id="eval-eval"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Comentarios</label><textarea id="eval-com" rows="2"></textarea></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Plan de mejora</label><textarea id="eval-plan" rows="2"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'eval-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveEvaluacion()" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveEvaluacion(){
  var d={
    accion:'evaluacion_crear',id_empleado:_profileId,
    periodo:$('eval-periodo').value,fecha:$('eval-fecha').value,
    competencias:parseInt($('eval-comp').value)||0,objetivos:parseInt($('eval-obj').value)||0,
    productividad:parseInt($('eval-prod').value)||0,trabajo_equipo:parseInt($('eval-teq').value)||0,
    puntualidad:parseInt($('eval-punt').value)||0,responsabilidad:parseInt($('eval-resp').value)||0,
    calidad:parseInt($('eval-cal').value)||0,comentarios:$('eval-com').value,
    plan_mejora:$('eval-plan').value,evaluador:$('eval-eval').value
  };
  apiPost(d,function(){toast('Evaluación agregada');showProfile(_profileId);});
}

function delEvaluacion(id){
  apiPost({accion:'evaluacion_eliminar',id_evaluacion:id},function(){toast('Evaluación eliminada');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Activos
   ═══════════════════════════════════════════ */
function activosHtml(p){
  var as=p.activos||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddActivo()"><i class="fas fa-plus"></i> Asignar Activo</button></div>';
  if(!as.length)h+='<p style="color:#94a3b8;">Sin activos asignados</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Tipo</th><th>Código</th><th>Descripción</th><th>Entrega</th><th>Estado</th><th>Responsable</th><th></th></tr></thead><tbody>';
    for(var i=0;i<as.length;i++){
      var a=as[i];
      h+='<tr><td><span class="tag">'+esc(a.tipo||'')+'</span></td><td><strong>'+bd(a.codigo_activo)+'</strong></td><td>'+esc(a.descripcion||'')+'</td><td>'+bd(a.fecha_entrega)+'</td><td><span class="badge badge-'+(a.estado||'ASIGNADO')+'">'+a.estado+'</span></td><td>'+bd(a.responsable)+'</td>'+
        '<td>'+(a.estado==='ASIGNADO'?'<button class="bi green" onclick="devolverActivo('+a.id_activo+')" title="Devolver"><i class="fas fa-undo"></i></button>':'')+
        '<button class="bi red" onclick="delActivo('+a.id_activo+')" title="Eliminar"><i class="fas fa-trash"></i></button></td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddActivo(){
  var pane=$('ptab-activos');if($('act-form'))return;
  var f=document.createElement('div');f.id='act-form';f.className='inline-form';
  var hoy=new Date().toISOString().slice(0,10);
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-laptop" style="color:#4f46e5;"></i> Asignar Activo</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Tipo *</label><select id="act-tipo"><option value="Notebook">Notebook</option><option value="PC">PC</option><option value="Monitor">Monitor</option><option value="Celular">Celular</option><option value="Vehículo">Vehículo</option><option value="Herramienta">Herramienta</option><option value="Uniforme">Uniforme</option><option value="Credencial">Credencial</option><option value="Licencia SW">Licencia SW</option><option value="Otro">Otro</option></select></div>'+
    '<div class="fld"><label>Código Activo</label><input id="act-codigo"></div>'+
    '<div class="fld"><label>Fecha Entrega</label><input id="act-entrega" type="date" value="'+hoy+'"></div>'+
    '<div class="fld"><label>Responsable</label><input id="act-resp"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Descripción</label><textarea id="act-desc" rows="1"></textarea></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Observaciones</label><textarea id="act-obs" rows="1"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'act-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveActivo()" style="flex:1;"><i class="fas fa-save"></i> Asignar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveActivo(){
  var d={
    accion:'activo_crear',id_empleado:_profileId,
    tipo:$('act-tipo').value,codigo_activo:$('act-codigo').value,
    descripcion:$('act-desc').value,fecha_entrega:$('act-entrega').value,
    responsable:$('act-resp').value,observaciones:$('act-obs').value
  };
  if(!d.tipo){toast('Tipo obligatorio','err');return;}
  apiPost(d,function(){toast('Activo asignado');showProfile(_profileId);});
}

function devolverActivo(id){
  apiPost({accion:'activo_devolver',id_activo:id},function(){toast('Activo devuelto');showProfile(_profileId);});
}

function delActivo(id){
  apiPost({accion:'activo_eliminar',id_activo:id},function(){toast('Activo eliminado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Historial
   ═══════════════════════════════════════════ */
function historialHtml(p){
  var hs=p.historial||[];
  var h='<div style="margin-bottom:10px;"><button class="btn btn-primary btn-sm" onclick="showAddHistorial()"><i class="fas fa-plus"></i> Agregar Evento</button></div>';
  if(!hs.length)h+='<p style="color:#94a3b8;">Sin historial registrado</p>';
  else{
    h+='<table class="w100"><thead><tr><th>Fecha</th><th>Tipo</th><th>Valor Anterior</th><th>Valor Nuevo</th><th>Descripción</th></tr></thead><tbody>';
    for(var i=0;i<hs.length;i++){
      var h2=hs[i];
      h+='<tr><td>'+bd(h2.fecha)+'</td><td><span class="tag">'+esc(h2.tipo||'')+'</span></td><td style="font-size:12px;color:#64748b;">'+esc(h2.valor_anterior||'')+'</td><td style="font-size:12px;color:#64748b;">'+esc(h2.valor_nuevo||'')+'</td><td style="font-size:12px;color:#64748b;">'+esc(h2.descripcion||'')+'</td></tr>';
    }
    h+='</tbody></table>';
  }
  return h;
}

function showAddHistorial(){
  var pane=$('ptab-historial');if($('hist-form'))return;
  var f=document.createElement('div');f.id='hist-form';f.className='inline-form';
  f.innerHTML=
    '<div style="font-weight:600;font-size:14px;margin-bottom:8px;"><i class="fas fa-history" style="color:#4f46e5;"></i> Agregar Evento Historial</div>'+
    '<div class="gr2">'+
    '<div class="fld"><label>Tipo *</label><select id="hist-tipo"><option value="Cambio cargo">Cambio cargo</option><option value="Cambio salarial">Cambio salarial</option><option value="Cambio sucursal">Cambio sucursal</option><option value="Ascenso">Ascenso</option><option value="Traslado">Traslado</option><option value="Sanción">Sanción</option><option value="Reconocimiento">Reconocimiento</option><option value="Otro">Otro</option></select></div>'+
    '<div class="fld"><label>Fecha</label><input id="hist-fecha" type="date"></div>'+
    '<div class="fld"><label>Valor Anterior</label><input id="hist-ant"></div>'+
    '<div class="fld"><label>Valor Nuevo</label><input id="hist-nuevo"></div>'+
    '<div class="fld" style="grid-column:1/-1;"><label>Descripción</label><textarea id="hist-desc" rows="2"></textarea></div>'+
    '</div>'+
    '<div style="display:flex;gap:8px;margin-top:4px;"><button class="btn-g" onclick="cancelForm(\'hist-form\')" style="flex:1;">Cancelar</button><button class="btn-p" onclick="saveHistorial()" style="flex:1;"><i class="fas fa-save"></i> Guardar</button></div>';
  pane.insertBefore(f,pane.firstChild.nextSibling);
}

function saveHistorial(){
  var d={
    accion:'historial_crear',id_empleado:_profileId,
    tipo:$('hist-tipo').value,fecha:$('hist-fecha').value,
    valor_anterior:$('hist-ant').value,valor_nuevo:$('hist-nuevo').value,
    descripcion:$('hist-desc').value
  };
  if(!d.tipo){toast('Tipo obligatorio','err');return;}
  apiPost(d,function(){toast('Evento agregado');showProfile(_profileId);});
}

/* ═══════════════════════════════════════════
   TAB: Auditoría
   ═══════════════════════════════════════════ */
function auditoriaHtml(p){
  var as=p.auditoria||[];
  if(!as.length)return '<p style="color:#94a3b8;">Sin registros de auditoría</p>';
  var h='<table class="w100"><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>IP</th></tr></thead><tbody>';
  for(var i=0;i<as.length;i++){
    var a=as[i];
    h+='<tr><td style="font-size:12px;color:#64748b;">'+bd(a.fecha)+'</td><td>'+esc(a.usuario||'')+'</td><td><span class="tag">'+esc(a.accion)+'</span></td><td style="font-size:12px;color:#64748b;">'+esc(a.detalle||'')+'</td><td style="font-size:11px;color:#94a3b8;">'+bd(a.ip)+'</td></tr>';
  }
  h+='</tbody></table>';
  return h;
}

/* ═══════════════════════════════════════════
   VINCULAR USUARIO
   ═══════════════════════════════════════════ */
function showVincularUsuario(id_empleado) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/core.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    if (xhr.status !== 200) { toast('Error al cargar usuarios', 'err'); return; }
    var users = JSON.parse(xhr.responseText);
    var list = '';
    for (var i = 0; i < users.length; i++) {
      list += '<option value="' + users[i].id_user + '">' + esc(users[i].nombre) + ' (' + esc(users[i].correo) + ')</option>';
    }
    var m = $('cm'), b = $('cbody');
    b.innerHTML = '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-link" style="color:#4f46e5;"></i> Vincular Usuario a Empleado</h3>' +
      '<div class="fld"><label>Seleccionar Usuario</label><select id="vu-user" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">' + list + '</select></div>' +
      '<div class="mcb"><button class="btn-g" onclick="$(\'cm\').classList.remove(\'show\')">Cancelar</button><button class="btn-p" onclick="vincularUsuario(' + id_empleado + ')"><i class="fas fa-link"></i> Vincular</button></div>';
    m.classList.add('show');
    m.onclick = function(e) { if (e.target === m) m.classList.remove('show'); };
  };
  xhr.send(JSON.stringify({ accion: 'usuarios' }));
}

function vincularUsuario(id_empleado) {
  var uid_v = parseInt($('vu-user').value);
  if (!uid_v) { toast('Seleccione un usuario', 'err'); return; }
  apiPost({ accion: 'vincular_usuario', id_empleado: id_empleado, id_user: uid_v }, function() {
    toast('Usuario vinculado');
    $('cm').classList.remove('show');
    showProfile(id_empleado);
  });
}

/* ═══════════════════════════════════════════
   API Helper & Utilities
   ═══════════════════════════════════════════ */
function apiPost(data,cb){
  var xhr=new XMLHttpRequest();
  xhr.open('POST','../assets/api/empleados.php',true);
  xhr.setRequestHeader('Content-Type','application/json');
  xhr.onload=function(){
    if(xhr.status>=200&&xhr.status<300){if(cb)cb();}
    else{try{var e=JSON.parse(xhr.responseText);toast(e.error||'Error','err');}catch(e2){toast('Error','err');}}
  };
  xhr.onerror=function(){toast('Error de conexión','err');};
  xhr.send(JSON.stringify(data));
}

/* ═══════════════════════════════════════════
   CREAR CREDENCIALES
   ═══════════════════════════════════════════ */
function showCrearCredenciales(id_empleado) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '../assets/api/empleados.php?id=' + id_empleado, true);
  xhr.onload = function() {
    if (xhr.status !== 200) { toast('Error al cargar empleado', 'err'); return; }
    var emp = JSON.parse(xhr.responseText);
    if (emp.id_user) {
      // Show credential info
      var m=$('cm'),b=$('cbody');
      b.innerHTML=
        '<h3><i class="fas fa-key" style="color:#059669;"></i> Credenciales de '+esc(emp.nombres)+' '+esc(emp.apellidos)+'</h3>'+
        '<div style="background:#ecfdf5;padding:12px;border-radius:8px;margin-bottom:12px;">'+
        '<p style="margin:0 0 4px;"><strong>Usuario ERP vinculado:</strong> #'+emp.id_user+'</p>'+
        '<p style="margin:0;font-size:13px;color:#64748b;">Las credenciales ya están creadas. Para cambiar la contraseña, use la función de recuperación.</p>'+
        '</div>'+
        '<div class="mcb"><button class="btn btn-outline btn-sm" onclick="$(\'cm\').classList.remove(\'show\')">Cerrar</button></div>';
      m.classList.add('show');
      m.onclick=function(e){if(e.target===m)m.classList.remove('show');};
      return;
    }

    var xhr2 = new XMLHttpRequest();
    xhr2.open('POST', '../assets/api/core.php', true);
    xhr2.setRequestHeader('Content-Type', 'application/json');
    xhr2.onload = function() {
      if (xhr2.status !== 200) { toast('Error al cargar roles', 'err'); return; }
      var roles = JSON.parse(xhr2.responseText);
      var rolesHtml = '';
      for (var i = 0; i < roles.length; i++) {
        rolesHtml += '<option value="' + roles[i].id_rol + '">' + esc(roles[i].nombre) + ' (' + roles[i].usuarios + ' usr)</option>';
      }

      var m = $('cm'), b = $('cbody');
      b.innerHTML =
        '<h3 style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:16px;"><i class="fas fa-key" style="color:#4f46e5;"></i> Crear Credenciales</h3>' +
        '<p style="font-size:13px;color:#64748b;margin-bottom:16px;">Se generará una cuenta de acceso para este empleado.</p>' +
        '<div class="fld"><label>Correo electrónico *</label><input id="cc-correo" type="email" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;" placeholder="empleado@empresa.cl"></div>' +
        '<div class="fld"><label>Contraseña *</label><input id="cc-pass" type="password" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;" placeholder="Mínimo 6 caracteres"></div>' +
        '<div class="fld"><label>Confirmar contraseña</label><input id="cc-pass2" type="password" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></div>' +
        '<div class="fld"><label>Rol (opcional)</label><select id="cc-rol" style="width:100%;padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"><option value="">Sin rol</option>' + rolesHtml + '</select></div>' +
        '<div id="cc-feedback" style="margin:12px 0;min-height:20px;"></div>' +
        '<div class="mcb"><button class="btn-g" onclick="$(\'cm\').classList.remove(\'show\')">Cancelar</button><button class="btn-p" onclick="crearCredenciales(' + id_empleado + ')"><i class="fas fa-key"></i> Crear Credenciales</button></div>';
      m.classList.add('show');
      m.onclick = function(e) { if (e.target === m) m.classList.remove('show'); };
    };
    xhr2.send(JSON.stringify({ accion: 'roles' }));
  };
  xhr.send();
}

function crearCredenciales(id_empleado) {
  var correo = ($('cc-correo') ? $('cc-correo').value : '').trim();
  var pass = $('cc-pass') ? $('cc-pass').value : '';
  var pass2 = $('cc-pass2') ? $('cc-pass2').value : '';
  var id_rol = parseInt(($('cc-rol') ? $('cc-rol').value : 0)) || 0;

  if (!correo) { toast('Correo requerido', 'err'); return; }
  if (!pass) { toast('Contraseña requerida', 'err'); return; }
  if (pass.length < 6) { toast('Contraseña debe tener al menos 6 caracteres', 'err'); return; }
  if (pass !== pass2) { toast('Las contraseñas no coinciden', 'err'); return; }

  var btn = document.querySelector('#cm .btn-p');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creando...'; }

  var xhr = new XMLHttpRequest();
  xhr.open('POST', '../assets/api/empleados.php', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onload = function() {
    var r = JSON.parse(xhr.responseText);
    if (xhr.status >= 200 && xhr.status < 300 && r.success) {
      var fb = $('cc-feedback');
      if (fb) fb.innerHTML = '<div style="background:#ecfdf5;color:#065f46;padding:12px;border-radius:8px;font-size:14px;"><strong>✓ Credenciales creadas</strong><br>Usuario: <strong>' + esc(r.usuario) + '</strong><br>Correo: <strong>' + esc(r.correo) + '</strong></div>';
      if (btn) { btn.style.display = 'none'; }
      toast('<i class="fas fa-check-circle"></i> Credenciales creadas exitosamente');
      setTimeout(function() { $('cm').classList.remove('show'); loadEmp(); }, 2000);
    } else {
      toast(r.error || 'Error al crear credenciales', 'err');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-key"></i> Crear Credenciales'; }
    }
  };
  xhr.onerror = function() { toast('Error de conexión', 'err'); if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-key"></i> Crear Credenciales'; } };
  xhr.send(JSON.stringify({ accion: 'crear_credenciales', id_empleado: id_empleado, correo: correo, password: pass, id_rol: id_rol }));
}

function cancelForm(id){var f=$(id);if(f)f.remove();}
