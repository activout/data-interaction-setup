// Please don't use  JQuery for DOM manipulation and form submission IRL. Please use React/Vue/Angular instead!

$(function () {

    let apiRoot = '/api/setup';


    function installFormSubmitHandler() {
        $("#addDatabaseForm").submit(function (event) {
            event.target.setAttribute('disabled̈́', 'disabled');

            const $this = $(this);
            const data = $this.serializeArray();

            $.ajax({
                type: 'POST',
                url: apiRoot,
                data: JSON.stringify({'email': data[0]['value']}),
                success: function () {
                    setTimeout(function () {
                        $this.hide();
                        $("#success").removeClass("d-none");
                    })
                },
                error: function (request, status, error) {
                    event.target.removeAttribute('disabled̈́');
                    console.error(error);
                    setTimeout(function () {
                        $("#failure").removeClass("d-none");
                    })
                },
                contentType: "application/json"
            });

            event.preventDefault();
        });
    }


    installFormSubmitHandler();
});
