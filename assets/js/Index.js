//::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::\\

//FUNCION PARA VALIDAR EL USUARIO E INGRESAR\\

//:::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::\\
function validateLogin() {
  // Obtener los valores del formulario de inicio de sesión
  var username = document.getElementById('username').value;
  var password = document.getElementById('password').value;

  // Crear un objeto con los datos del usuario
  var userData = {
      'username': username,
      'password': password
  };

  // Crear una cadena de consulta codificada para enviar los datos
  var formData = new URLSearchParams();
  for (var key in userData) {
      formData.append(key, userData[key]);
  }

  // Enviar los datos a través de AJAX a login.php
  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'assets/PHP/login.php', true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onreadystatechange = function() {
      if (xhr.readyState === 4) {
          var data = null;
          try {
              data = JSON.parse(xhr.responseText);
          } catch (error) {
              data = { ok: false, mensaje: xhr.responseText || 'Error de inicio de sesion' };
          }
          // Manejar la respuesta del servidor
          alert(data.mensaje || xhr.responseText); // Muestra la respuesta del servidor en una alerta
          if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
              if (data.id_user) sessionStorage.setItem('user_id', data.id_user);
              if (data.nombre) sessionStorage.setItem('user_name', data.nombre);
              if (data.nombre_completo) sessionStorage.setItem('nombre_completo', data.nombre_completo);
              window.location.href = 'public/Inicio.html';//RUTA DE INICIO
              return;
          }
          // Si el inicio de sesión es exitoso, redirigir al usuario a otra página
          if (xhr.responseText.includes('Inicio de sesión exitoso')) {
              window.location.href = 'public/Inicio.html';//RUTA DE INICIO
          }
      }
  };
  xhr.send(formData);
}


//_________________________________________________________________________________________________________\\

//:::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::.::.:.:.\\

//MOSTRAR Y ESCONDER POPUP DE CREAR CUENTA

//::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::\\


function showCreateAccountPopup() {
    document.getElementById('create-account-popup').style.display = 'block';
  }

  function hideCreateAccountPopup() {
    document.getElementById('create-account-popup').style.display = 'none';
  }


//_________________________________________________________________________________________________________\\

//:::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::\\

  //FUNCION  CREAR CUENTA

//:::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::\\

  function createAccount() {
    // Obtener los valores del formulario de creación de cuenta
    var nombre = document.getElementById('new-username').value;
    var correo = document.getElementById('e-mail').value;
    var password = document.getElementById('confirm-password').value;
  
    // Crear un objeto con los datos del usuario
    var userData = {
      'new-username': nombre,
      'e-mail': correo,
      'confirm-password': password
    };
  
    // Crear una cadena de consulta codificada para enviar los datos
    var formData = new URLSearchParams();
    for (var key in userData) {
      formData.append(key, userData[key]);
    }
  
    // Enviar los datos a través de AJAX a create_account.php
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'assets/PHP/create_account.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4 && xhr.status === 200) {
        // Manejar la respuesta del servidor
        alert(xhr.responseText); // Muestra la respuesta del servidor en una alerta
        // Limpia los campos del formulario si la creación de cuenta fue exitosa
        if (xhr.responseText.includes('Cuenta creada exitosamente')) {
          document.getElementById('nombre').value = '';
          document.getElementById('correo').value = '';
          document.getElementById('password').value = '';
        }
      }
    };
    xhr.send(formData);
  }


//_________________________________________________________________________________________________________\\


