// Please don't use  JQuery for DOM manipulation and form submission IRL. Please use React/Vue/Angular instead!

$(function () {

    let apiRoot = '/api/setup';


    function installFormSubmitHandler() {
        $("#addDatabaseForm").submit(function (event) {
            event.target.setAttribute('disabled̈́', 'disabled');

            const data = $(this).serializeArray();

            $.ajax({
                type: 'POST',
                url: apiRoot,
                data: JSON.stringify({'email': data[0]['value']}),
                success: function (counter, status) {
                    event.target.removeAttribute('disabled̈́');
                },
                contentType: "application/json",
                dataType: 'json'
            });

            event.preventDefault();
        });
    }


    installFormSubmitHandler();
});
