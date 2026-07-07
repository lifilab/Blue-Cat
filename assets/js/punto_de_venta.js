//variable del coste total de la venta
var totalPrice = 0;
//variable del pago total de la venta
var totalPayment = 0;
// variable de la diferencia entre el coste y el pago
var change = 0;
// array con el metodo de pago y monto total de cada uno
var paymentRecords = []
// nombre y precio de cada producto agregado al carrito
var cartItemsArray = [];
var posPermissions = { cambiar_precios: false };
var priceModificationListenerAttached = false;

loadPosPermissions();
loadProducts();

function parseJsonResponse(text) {
    try {
        return JSON.parse(text);
    } catch (error) {
        return { ok: false, mensaje: text || 'Respuesta invalida del servidor.' };
    }
}

function loadPosPermissions() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '../assets/PHP/obtener_permisos_usuario.php', true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var data = parseJsonResponse(xhr.responseText);
            if (data.ok && data.permisos && data.permisos.pos) {
                posPermissions = data.permisos.pos;
            }
        }
    };
    xhr.send();
}

function canChangePrices() {
    return posPermissions && posPermissions.cambiar_precios === true;
}


// Agregar un event listener para el evento click al botón pagar
document.querySelector('.pagar-btn').addEventListener('click', function () {
    // Obtener el costo total de la venta
    var totalPrice = getTotalPrice();
    
    // Calcular el valor del IVA
    var iva = Math.round(totalPrice * 0.19);

    // Calcular el precio total con el IVA incluido
    var totalPriceWithIVA = totalPrice + iva;
    
    // Calcular la diferencia entre el costo y el pago
    var change = Math.round(totalPayment - totalPrice);

    // Obtener el nombre, cantidad y precio de cada producto agregado al carrito
    var cartItemsArray = storeCartItems();
    var paymentRecords = storePaymentsRecord();

    // Verificar si el carrito está vacío
    if (cartItemsArray.length === 0) {
        alert("El carrito está vacío!");
    } else if (paymentRecords.length === 0) {
        alert("Debe seleccionar al menos un método de pago!");
    } else {
        // Convertir paymentRecords a JSON
        var paymentRecordsJson = JSON.stringify(paymentRecords);

        // Convertir cartItemsArray a JSON
        var cartItemsArrayJson = JSON.stringify(cartItemsArray);

        // Crear un objeto con los datos que no son arrays
        var saleData = {
            totalPrice: Math.round(totalPrice),
            totalPayment: Math.round(totalPayment),
            change: change
        };

        // Convertir el objeto a JSON
        var saleDataJson = JSON.stringify(saleData);

        // Enviar los datos a través de AJAX a ../assets/PHP/pedidos.php
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '../assets/PHP/pedidos.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState == 4) {
                // La solicitud se completó y la respuesta está lista
                var response = parseJsonResponse(xhr.responseText);

                if (xhr.status !== 200 || !response.ok) {
                    alert(response.mensaje || 'No se pudo registrar la venta.');
                    return;
                }

                console.log(xhr.responseText);

                // Crear el contenido del recibo
                var receiptContent = `
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        font-size: 10px;
                    }
                    h1 {
                        font-size: 14px;
                        font-weight: bold;
                        margin-bottom: 5px;
                        text-align: center;
                    }
                    .contact-info {
                        font-size: 8px;
                    }
                    .receipt-section {
                        margin-bottom: 5px;
                    }
                    .receipt-section h2 {
                        font-size: 12px;
                        margin-bottom: 3px;
                    }
                    p {
                        margin-bottom: 3px;
                    }
                    ul {
                        list-style: none;
                        padding-left: 0;
                        margin-top: 0;
                    }
                    li {
                        margin-bottom: 3px;
                    }
                    strong {
                        font-weight: bold;
                    }
                    em {
                        font-style: italic;
                    }
                </style>
                <div class="receipt-section">
                    <h1>"San Fernando"</h1>
                    <h1>minimarket</h1>
                    <div class="contact-info">
                        <p>RUT: 76086428-5</p>
                        <p>distribuidoralamartina@gmail.com</p>
                        <p>distribuidoralamartina.cl</p>
                    </div>
                </div>
                <div class="receipt-section">
                    <h2>Productos</h2>
                    <ul>`;
                
                cartItemsArray.forEach(function (item) {
                    receiptContent += `<li>${item.name} x${item.quantity} - $${Math.round(item.price * item.quantity)}</li>`;
                });
                
                receiptContent += `</ul>
                </div>
                <div class="receipt-section">
                    <h2>Resumen de Pago</h2>
                    <p><strong>Valor neto:</strong> $${Math.round(totalPrice-iva)}</p>
                    <p><strong>IVA (19%):</strong> $${iva}</p>
                    <p><strong>Valor total:</strong> $${Math.round(totalPrice)}</p>
                    <p><strong> Pago:</strong> $${Math.round(totalPayment)}</p>
                    <p><strong>Cambio:</strong> $${change}</p>
                </div>
                <p><em>¡Gracias por su compra!</em></p>
                `;

                // Crear un nuevo documento HTML con el contenido del recibo
                var receiptDocument = document.implementation.createHTMLDocument('');
                receiptDocument.body.innerHTML =  receiptContent;

                // Función para abrir la ventana emergente después de cargar el contenido
                function openReceiptWindow() {
                    // Abrir el recibo en una nueva ventana
                    var receiptWindow = window.open('', '_blank');

                    receiptWindow.document.write(receiptDocument.documentElement.outerHTML);
                    receiptWindow.document.close();

                    // Opción para imprimir el recibo físicamente
                    receiptWindow.print();

                    // Cerrar la ventana cuando se haga clic fuera de ella
                    receiptWindow.document.addEventListener('click', function () {
                        receiptWindow.close();
                    });

                    // Agregar un event listener para el evento keydown en la ventana emergente
                    receiptWindow.addEventListener('keydown', function (event) {
                        if (event.keyCode === 27) { // 27 is the key code for Escape
                            receiptWindow.close();
                        }
                    });

                    // Recargar la página principal cuando la ventana emergente se cierra
                    receiptWindow.addEventListener('unload', function () {
                        location.reload();
                    });
                }

                // Esperar un momento para asegurar que el documento del recibo esté completamente cargado
                setTimeout(openReceiptWindow, 100);
            }
        };

        // Enviar todos los datos como JSON
        xhr.send(JSON.stringify({ saleData: saleDataJson, paymentRecords: paymentRecordsJson, cartItemsArray: cartItemsArrayJson }));
    }
});

// Cerrar la ventana emergente si se cancela la impresión
window.addEventListener('beforeprint', function () {
    window.close();
});
// Añadir el evento de clic al documento principal (u otro documento deseado)
document.addEventListener('click', function () {
    receiptWindow.close();
});


// Función para almacenar productos, precios y cantidades del carrito en un array
function storeCartItems() {
    // Array para almacenar los productos, precios, cantidades y IDs del carrito
    var cartItemsArray = [];

    // Obtener la lista de elementos del carrito
    var cartItems = document.getElementById('cart-items').getElementsByTagName('li');

    // Recorrer cada elemento del carrito y extraer el nombre, precio, cantidad y ID del producto
    for (var i = 0; i < cartItems.length; i++) {
        var itemText = cartItems[i].innerText;
        // Buscar el índice del símbolo del dólar
        var dollarIndex = itemText.indexOf('$');
        // Extraer el precio a partir del índice del símbolo del dólar
        var itemPrice = parseFloat(itemText.substring(dollarIndex + 1).trim());
        // Extraer el nombre del producto y la cantidad
        var itemNameWithQuantity = itemText.substring(0, dollarIndex).trim();
        var quantityIndex = itemNameWithQuantity.lastIndexOf('x');
        var itemName = itemNameWithQuantity.substring(0, quantityIndex).trim();
        var itemQuantity = parseInt(itemNameWithQuantity.substring(quantityIndex + 1).trim());
        // Obtener el id_producto del atributo data-id del elemento li
        var idProducto = cartItems[i].getAttribute('data-id');
        // Agregar el producto, precio, cantidad e ID al array
        cartItemsArray.push({ id_producto: idProducto, name: itemName, price: itemPrice, quantity: itemQuantity });
    }

    // Devolver el array con los productos, precios, cantidades e IDs del carrito
    return cartItemsArray;
}

var paymentRecords = []; // Inicializar paymentRecords como un array vacío

// Función para agregar un nuevo registro de pago
function setPaymentAmountAndType(paymentType) {
    // Obtener el precio total de los productos en el carrito
    var totalPrice = getTotalPrice();

    // Calcular el total de los pagos registrados
    var totalPaymentRegistered = paymentRecords.reduce(function (total, record) {
        return total + record.value;
    }, 0);

    // Calcular la diferencia entre el total de los pagos y el total de los productos
    var difference = Math.abs(totalPaymentRegistered - totalPrice);

    // Solicitar el monto pagado, utilizando la diferencia como valor por defecto
    var amount = parseFloat(prompt('Ingrese el monto pagado:', difference));
    console.log("Monto ingresado:", amount);
    if (!isNaN(amount)) {
        // Ajustar el monto para asegurarse de que sea positivo
        amount = Math.max(amount, 0);

        // Almacenar el monto pagado en el objeto de registros de pago
        paymentRecords.push({ name: paymentType, value: amount });
        console.log("paymentRecords después de agregar el monto:", paymentRecords);

        // Sumar el monto pagado al total de pagos
        totalPayment = paymentRecords.reduce(function (total, record) {
            return total + record.value;
        }, 0);
        console.log("Total de pagos actualizado:", totalPayment);

        // Mostrar y sumar todos los métodos de pago
        var paymentString = 'Pago: ';
        paymentRecords.forEach(function (record) {
            paymentString += record.name + ': $' + record.value.toFixed(2) + ', ';
        });
        paymentString += 'Total: $' + totalPayment.toFixed(2);

        // Obtener el elemento de pago
        var paymentElement = document.querySelector('#pago .pago');

        // Mostrar el monto y el tipo de pago en el elemento
        paymentElement.textContent = paymentString;

        // Calcular el cambio
        calculateChange(totalPayment, totalPrice);
    }
}

// Función para almacenar registros de pagos en un array
function storePaymentsRecord() {
    return paymentRecords; // Devolver el array con los registros de pagos
}

// Función para manejar el click en el botón de PAGAR
document.querySelector('.pagar-btn').addEventListener('click', function () {
    // Almacenar los productos y precios del carrito en un array
    var cartItems = storeCartItems();
    // Mostrar los productos y precios del carrito en la consola
    console.log(cartItems);
});

// Función para cargar los productos desde el servidor
function loadProducts() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '../assets/PHP/obtener_productos.php', true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var productos = JSON.parse(xhr.responseText);
            var productGrid = document.getElementById('product-grid');
            productGrid.innerHTML = ''; // Limpiamos el contenido previo del contenedor

            productos.forEach(function (producto) {
                var idProducto = producto.id_producto;
                // Crear el contenedor del producto
                var productDiv = document.createElement('div');
                productDiv.classList.add('product');

                // Crear un div invisible que cubra toda la tarjeta del producto
                var overlayDiv = document.createElement('div');
                overlayDiv.classList.add('overlay');
                overlayDiv.addEventListener('click', function () {
                    addToCart(producto.nombre_producto, parseFloat(producto.precio_venta), idProducto);
                });

                // Agregar el nombre del producto como un elemento clicable
                var productName = document.createElement('h3');
                productName.textContent = producto.nombre_producto;

// Agregar el precio del producto como un elemento cliclable
var productPrice = document.createElement('h3');
productPrice.textContent = '$' + Math.round(parseFloat(producto.precio_venta) * 100) / 100;


                // Agregar los elementos al contenedor del producto
                productDiv.appendChild(overlayDiv);
                productDiv.appendChild(productName);
                productDiv.appendChild(productPrice);

                // Agregar el contenedor del producto a la grid
                productGrid.appendChild(productDiv);
            });

            // Llamar a la función para permitir la modificación del precio después de cargar los productos
            modifyProductPrice();
        }
    };
    xhr.send();
}
// Obtener referencia al campo de entrada de búsqueda
const searchInput = document.getElementById('search-input');

// Obtener referencia a la tabla de productos y a todas las filas de productos
const productGrid = document.getElementById('product-grid');

// Función para realizar la búsqueda y filtrar los productos
function searchProducts() {
    // Obtener el texto de búsqueda
    const searchText = searchInput.value.trim();
  
    // Verificar si el texto de búsqueda no está vacío
    if (searchText !== "") {
      // Inicializar la solicitud XMLHttpRequest
      var xhr = new XMLHttpRequest();
      xhr.open('GET', '../assets/PHP/obtener_productos.php?search=' + encodeURIComponent(searchText), true);
  
      // Manejar la respuesta de la solicitud
      xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 300) {
          // La solicitud fue exitosa, parsear la respuesta JSON
          const resultados = JSON.parse(xhr.responseText);
          // Llamar a la función para mostrar los resultados
          mostrarResultadosBusqueda(resultados);
        } else {
          // La solicitud no fue exitosa, puedes manejar el error aquí si es necesario
          console.error("Error en la solicitud HTTP");
        }
      };
  
      // Manejar errores de red u otros errores
      xhr.onerror = function() {
        console.error("Error de red o en la solicitud HTTP");
      };
  
      // Enviar la solicitud
      xhr.send();
    }
  }
  
// Función para mostrar los resultados de la búsqueda en el formato de vista de productos
function mostrarResultadosBusqueda(resultados) {
    // Referencia al contenedor de productos
    var productGrid = document.getElementById('product-grid');
  
    // Limpiar el contenido previo del contenedor
    productGrid.innerHTML = '';
  
    // Verificar si hay resultados
    if (resultados.length > 0) {
      // Mostrar cada resultado en el contenedor de productos
      resultados.forEach(function(resultado) {
        var idProducto = resultado.id_producto;
        
        // Crear el contenedor del producto
        var productDiv = document.createElement('div');
        productDiv.classList.add('product');
  
        // Crear un div invisible que cubra toda la tarjeta del producto
        var overlayDiv = document.createElement('div');
        overlayDiv.classList.add('overlay');
        overlayDiv.addEventListener('click', function () {
          addToCart(resultado.nombre_producto, parseFloat(resultado.precio_venta), idProducto);
        });
  
        // Agregar el nombre del producto como un elemento clicable
        var productName = document.createElement('h3');
        productName.textContent = resultado.nombre_producto;
  
        // Agregar el precio del producto como un elemento clicable
        var productPrice = document.createElement('p');
        productPrice.textContent = '$' + parseFloat(resultado.precio_venta).toFixed(2);
  
        // Agregar el código de barras del producto como un elemento clicable
        var productBarcode = document.createElement('p');
        productBarcode.textContent = resultado.codigo_de_barras;
  
        // Agregar los elementos al contenedor del producto
        productDiv.appendChild(overlayDiv); // Agregar el div de superposición primero para que esté en la parte superior
        productDiv.appendChild(productName);
        productDiv.appendChild(productPrice);
        productDiv.appendChild(productBarcode);
  
        // Agregar el contenedor del producto al contenedor de productos
        productGrid.appendChild(productDiv);
      });
    } else {
      // Si no hay resultados, mostrar un mensaje en el contenedor de productos
      var noResultsMessage = document.createElement('p');
      noResultsMessage.textContent = 'No se encontraron resultados.';
      productGrid.appendChild(noResultsMessage);
    }
  }
  
  
  // Agregar un evento de escucha para el evento de pulsación de tecla en el campo de búsqueda
  searchInput.addEventListener('keydown', function(event) {
    // Verificar si la tecla presionada es "Enter" (código de tecla 13)
    if (event.keyCode === 13) {
      // Llamar a la función searchProducts cuando se presiona "Enter"
      searchProducts();
    }
  });

// Agregar un evento de escucha para la tecla "Suprimir" (Delete)
document.addEventListener('keydown', function (event) {
    if (event.key === 'Delete') {
        RemoveLastItem();
    }
});

// Función para quitar productos del carrito
function RemoveLastItem() {
    // Obtener referencia al carrito de compras
    var cart = document.getElementById('cart-items');

    // Obtener el último elemento del carrito
    var lastCartItem = cart.lastElementChild;

    // Verificar si hay elementos en el carrito
    if (lastCartItem) {
        // Quitar el último elemento del carrito
        cart.removeChild(lastCartItem);
        // Calcular y mostrar el nuevo precio total
        getTotalPrice();
    }
}
// Función para quitar un producto del carrito
function removeFromCart(productName) {
    // Buscar el elemento del producto en el carrito
    var cartItem = document.querySelector('#cart-items li[data-name="' + productName + '"]');
    
    // Si el producto está en el carrito, eliminarlo
    if (cartItem) {
        cartItem.parentNode.removeChild(cartItem);
        
        // Calcular y mostrar el nuevo precio total
        getTotalPrice();
        calculateChange();
    }
}

// Función para agregar productos al carrito
function addToCart(productName, productPrice, idProducto) {
    // Verificar si el producto ya está en el carrito
    var existingItem = document.querySelector('#cart-items li[data-name="' + productName + '"]');

    // Si el producto ya está en el carrito, actualizar la cantidad y el precio total
    if (existingItem) {
        // Obtener la cantidad actual del producto
        var currentQuantity = parseInt(existingItem.getAttribute('data-quantity'));
        // Incrementar la cantidad
        currentQuantity++;
        // Actualizar la cantidad en el atributo de datos
        existingItem.setAttribute('data-quantity', currentQuantity);
        // Actualizar el precio total
        var totalPrice = currentQuantity * productPrice;
        existingItem.innerText = productName + ' x' + currentQuantity + ' ' + '$' + totalPrice.toFixed(2);
    } else {
        // Si el producto no está en el carrito, crear un nuevo elemento de lista para el producto
        var cartItem = document.createElement('li');
        // Establecer atributos de datos para el nombre del producto y la cantidad
        cartItem.setAttribute('data-name', productName);
        cartItem.setAttribute('data-quantity', 1);
        // Agregar el id_producto al elemento de lista
        cartItem.setAttribute('data-id', idProducto);
        // Formatear el texto con el nombre del producto, cantidad y precio
        cartItem.innerText = productName + ' x1 ' + '$' + productPrice.toFixed(2);
        // Agregar el elemento de lista al carrito
        var cart = document.getElementById('cart-items');
        cart.appendChild(cartItem);
    }

    // Agregar el botón de "Quitar" (siempre)
    var removeButton = document.createElement('button');
    removeButton.innerText = 'Quitar';
    removeButton.classList.add('cart-remove-button'); // Agregar la clase CSS al botón
    removeButton.addEventListener('click', function() {
        removeFromCart(productName);
    });
    // Siempre adjuntar el botón al elemento de lista
    existingItem ? existingItem.appendChild(removeButton) : cartItem.appendChild(removeButton);

    // Calcular y mostrar el nuevo precio total
    getTotalPrice();
    calculateChange();
}

// Obtener referencia al contenedor de productos
const product_Grid = document.getElementById('product-grid');

// Agregar un evento de clic a la grid de productos
productGrid.addEventListener('click', function (event) {
    // Verificar si el clic se originó en un elemento de producto
    if (event.target.classList.contains('product')) {
        // Obtener los datos del producto clickeado
        var productName = event.target.querySelector('h3').innerText;
        var productPrice = event.target.querySelector('p:nth-of-type(1)').innerText.split(' ')[1]; // Obtener el precio del producto

        // Agregar el producto al carrito
        addToCart(productName, productPrice);
    }
});


// Función para manejar el escaneo del código de barras
function handleBarcodeScan(barcode) {
    console.log('Código de barras escaneado:', barcode); // Verificar si el valor del código de barras se está capturando correctamente

    // Verificar si el input está vacío
    if (barcode.trim() === "") {
        // Si el input está vacío, mostrar todos los productos
        loadProducts();
        return;
    }

    // Realizar una solicitud AJAX para obtener la lista de productos
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '../assets/PHP/obtener_productos.php', true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var productos = JSON.parse(xhr.responseText);
            console.log('Productos obtenidos:', productos); // Verificar si se obtiene la lista de productos correctamente

            // Iterar sobre los productos para encontrar el que coincide con el código de barras escaneado
            for (var i = 0; i < productos.length; i++) {
                if (productos[i].codigo_de_barras === barcode) {
                    console.log('Producto encontrado:', productos[i]); // Verificar si se encuentra el producto correspondiente al código de barras
                    // Una vez encontrado el producto, agregarlo al carrito
                    addToCart(productos[i].nombre_producto, parseFloat(productos[i].precio_venta), productos[i].id_producto);
                    // Salir del bucle una vez que se agregue el producto al carrito
                    return;
                }
            }
        }
    };
    xhr.send();
}


// Función para obtener el precio total de los productos en el carrito
function getTotalPrice() {
    // Obtener referencia al carrito de compras
    var cart = document.getElementById('cart-items');

    // Obtener todos los elementos de lista dentro del carrito
    var cartItems = cart.getElementsByTagName('li');

    totalPrice = 0;

    // Iterar sobre los elementos del carrito y sumar los precios
    for (var i = 0; i < cartItems.length; i++) {
        // Obtener el texto del elemento de lista
        var itemText = cartItems[i].innerText;
        // Extraer el precio del texto (se asume que el precio está al final del texto y precedido por '$')
        var price = parseFloat(itemText.split('$')[1]);
        // Sumar el precio al total
        totalPrice += price;
    }

    // Mostrar el precio total en el elemento HTML correspondiente
    var totalPriceElement = document.querySelector('#precio-total .total');
    totalPriceElement.textContent = 'Total: $' + totalPrice.toFixed(2);

    return totalPrice;
}
// Agregar event listeners a los botones de método de pago
document.getElementById('efectivo-btn').addEventListener('click', function () {
    setPaymentAmountAndType('Efectivo');
});

document.getElementById('tarjeta-btn').addEventListener('click', function () {
    setPaymentAmountAndType('Tarjeta');
});

document.getElementById('transferencia-btn').addEventListener('click', function () {
    setPaymentAmountAndType('Transferencia');
});


// Función para calcular el cambio
function calculateChange(paymentAmount) {
    // Obtener el precio total de los productos en el carrito
    var totalPrice = getTotalPrice();

    // Calcular la diferencia entre el pago y el total
    change = paymentAmount - totalPrice;

    // Obtener el elemento de cambio
    var changeElement = document.querySelector('#cambio .cambio');

    // Mostrar el cambio en el elemento
    changeElement.textContent = 'Cambio: $' + change.toFixed(2);
}


// Función para cancelar la venta (actializar la pagina)
function cancelSale() {
    window.location.reload();
}

// Evento de clic para el botón "Cancelar venta"
document.getElementById('cancelar-venta').addEventListener('click', function () {
    cancelSale();
});

// Escuchar el evento del lector de código de barras
document.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && document.activeElement.tagName === 'INPUT') {
        // Obtener el valor del campo de entrada donde se escanea el código de barras
        var barcodeValue = document.activeElement.value;
        console.log('Valor del código de barras:', barcodeValue); // Verificar si el valor del código de barras se está capturando correctamente
        // Limpiar el campo de entrada después de escanear el código de barras
        document.activeElement.value = '';
        // Manejar el escaneo del código de barras
        handleBarcodeScan(barcodeValue);
    }
});

// Función para manejar los atajos de teclado
function handleShortcut(event) {
    switch (event.key) {
        case 'Insert':
            document.getElementById('efectivo-btn').click();
            break;
        case 'Home':
            document.getElementById('tarjeta-btn').click();
            break;
        case 'End':
            document.getElementById('transferencia-btn').click();
            break;
        case 'PageUp':
            document.getElementById('pagar-btn').click();
            break;
        default:
            break;
    }
}

function modifyProductPrice() {
    if (priceModificationListenerAttached) {
        return;
    }

    priceModificationListenerAttached = true;

    // Obtener referencia al carrito de compras
    var cart = document.getElementById('cart-items');

    // Agregar un event listener a cada elemento del carrito
    cart.addEventListener('click', function (event) {
        // Verificar si el clic se originó en un elemento de producto
        if (event.target.tagName === 'LI') {
            if (!canChangePrices()) {
                alert('No tiene permiso para cambiar precios en POS.');
                return;
            }

            // Obtener el texto del elemento de lista
            var itemText = event.target.innerText;

            // Extraer el nombre y el precio del producto del texto
            var productName = itemText.split(' .......')[0];
            var currentPrice = parseFloat(itemText.split('$')[1]);

            // Solicitar al usuario el nuevo precio
            var newPrice = parseFloat(prompt('Ingrese el nuevo precio para ' + productName + ':', currentPrice));

            // Verificar si el nuevo precio es válido
            if (!isNaN(newPrice) && newPrice >= 0) {
                // Eliminar el precio anterior del texto del elemento de lista
                var newText = itemText.replace(/\$[\d,]+\.\d{2}/, ''); // Elimina el precio en formato $X.XX

                // Agregar el nuevo precio al texto del elemento de lista
                newText += '$' + newPrice.toFixed(2);

                // Reemplazar el texto del elemento de lista con el nuevo texto
                event.target.innerText = newText;

                // Recalcular el precio total
                getTotalPrice();

                // Recalcular el cambio con el nuevo precio
                var totalPayment = getTotalPrice();
                calculateChange(totalPayment);
            } else {
                // Si el usuario cancela o ingresa un precio inválido, no hacer ningún cambio
                alert('Precio inválido. No se realizaron cambios.');
            }
        }


    });
}
// Agregar un event listener para el evento keydown
document.addEventListener('keydown', function (event) {
    // Manejar el atajo de teclado solo si la tecla presionada es "|"
    if (event.key === '*') {
        if (!canChangePrices()) {
            alert('No tiene permiso para cambiar precios en POS.');
            return;
        }

        // Obtener referencia al último elemento agregado al carrito
        var lastCartItem = document.getElementById('cart-items').lastElementChild;
        // Verificar si hay un elemento en el carrito
        if (lastCartItem) {
            // Obtener el texto del último elemento del carrito
            var itemText = lastCartItem.innerText;
            // Extraer el nombre del producto
            var productName = itemText;
            // Obtener el precio actual del producto
            var currentPrice = parseFloat(itemText.split('$')[1]);
            // Solicitar al usuario el nuevo precio
            var newPrice = parseFloat(prompt('Ingrese el nuevo precio para ' + productName + ':', currentPrice));
            // Verificar si el nuevo precio es válido
            if (!isNaN(newPrice) && newPrice >= 0) {
                // Eliminar el precio anterior del texto del elemento de lista
                var newText = itemText.replace(/\$[\d,]+\.\d{2}/, ''); // Elimina el precio en formato $X.XX

                // Agregar el nuevo precio al texto del elemento de lista
                newText += '$' + newPrice.toFixed(2);

                // Reemplazar el texto del elemento de lista con el nuevo texto
                lastCartItem.innerText = newText;

                // Recalcular el precio total
                getTotalPrice();

                // Recalcular el cambio con el nuevo precio
                var totalPayment = getTotalPrice();
                calculateChange(totalPayment);
            } else {
                // Si el usuario cancela o ingresa un precio inválido, no hacer ningún cambio
                alert('Precio inválido. No se realizaron cambios.');
            }
        }
    }
    totalPrice = getTotalPrice();

});

// Agregar un event listener para el evento keydown
document.addEventListener('keydown', handleShortcut);


document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-input');
    const popup = document.getElementById('popup');
  
    // Mantener el enfoque en el campo de búsqueda, excepto cuando se hace clic en el pop-up
    document.addEventListener('click', function(event) {
      if (!popup.contains(event.target)) {
        searchInput.focus();
      }
    });
  
    // Asegurarse de que el campo de entrada está enfocado al cargar la página
    searchInput.focus();
  });
// Llamar a la función para enfocar el campo de búsqueda al cargar la página
focusSearchInput();
