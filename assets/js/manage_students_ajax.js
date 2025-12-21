// Render section buttons
document.addEventListener('DOMContentLoaded', function() {
    const sectionButtons = document.getElementById('section-buttons');
    const yearFilter = document.getElementById('year-filter');
    const studentsList = document.getElementById('students-list');
    const searchInput = document.getElementById('studentSearchInput');
    const paginationWrapper = document.getElementById('studentPaginationWrapper');
    let activeSection = null;
    let activeYear = YEAR_LEVELS.length > 0 ? YEAR_LEVELS[0] : '';
    let allStudentsData = [];
    let lastSection = null;
    let currentDeleteStudentId = null;

    // Render section buttons for the selected year
    function renderSectionButtons() {
        sectionButtons.innerHTML = '';
        const filteredSections = SECTIONS.filter(section => section.year_level == activeYear);
        filteredSections.forEach(section => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-outline-primary';
            btn.textContent = section.name;
            btn.dataset.sectionId = section.id;
            btn.onclick = function() {
                activeSection = section.id;
                highlightActiveSection();
                fetchStudents();
            };
            sectionButtons.appendChild(btn);
        });
        // Auto-select first section if available
        if (filteredSections.length > 0) {
            activeSection = filteredSections[0].id;
            highlightActiveSection();
            fetchStudents();
        } else {
            activeSection = null;
            studentsList.innerHTML = '<div class="alert alert-info">No sections for this year level.</div>';
        }
    }

    function highlightActiveSection() {
        Array.from(sectionButtons.children).forEach(btn => {
            btn.classList.toggle('active', btn.dataset.sectionId == activeSection);
        });
    }

    // Year filter change
    yearFilter.addEventListener('change', function() {
        activeYear = this.value;
        renderSectionButtons();
    });

    // Fetch students via AJAX
    function fetchStudents() {
        if (!activeSection) {
            studentsList.innerHTML = '<div class="alert alert-info">Please select a section to view students.</div>';
            return;
        }
        studentsList.innerHTML = '';
        fetch(`fetch_students.php?section_id=${activeSection}&year_level=${activeYear}`)
            .then(res => res.json())
            .then(data => {
                allStudentsData = data;
                lastSection = activeSection;
                renderStudentTable(data);
            })
            .catch(() => {
                studentsList.innerHTML = '<div class="alert alert-danger">Failed to fetch students.</div>';
            });
    }

    // Render student table from data
    function renderStudentTable(data) {
        if (data.length === 0) {
            studentsList.innerHTML = '<div class="alert alert-warning">No students found for this section/year.</div>';
        } else {
            let html = `<div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-bordered table-striped mb-0"><thead><tr><th>Student ID</th><th>Full Name</th><th>Email</th><th>Created At</th><th>Actions</th></tr></thead><tbody>`;
            data.forEach(student => {
                // Compose full name if not present
                if (!student.full_name) {
                    let names = [];
                    if (student.last_name) names.push(student.last_name.toUpperCase());
                    if (student.first_name) names.push(student.first_name);
                    if (student.middle_name) names.push(student.middle_name.charAt(0).toUpperCase() + '.');
                    if (student.suffix_name) names.push(student.suffix_name);
                    student.full_name = names.join(', ');
                }
                html += `<tr>
                    <td>${student.student_id}</td>
                    <td>${student.full_name}</td>
                    <td>${student.email}</td>
                    <td>${student.created_at ? student.created_at : ''}</td>
                    <td class="text-center">
                        <div class="d-flex gap-2 justify-content-center align-items-center">
                            <a href="#" class="text-success d-inline-flex align-items-center justify-content-center" 
                                style="width: 32px; height: 32px;"
                                data-bs-toggle="modal" 
                                data-bs-target="#captureIdModal"
                                data-student-id="${student.id}"
                                data-student-name="${student.full_name}"
                                title="Capture ID Photo">
                                <i class="bi bi-camera fs-5"></i>
                            </a>
                            <a href="#" class="text-info d-inline-flex align-items-center justify-content-center" 
                                style="width: 32px; height: 32px;"
                                data-bs-toggle="modal" 
                                data-bs-target="#viewStudentModal" 
                                data-id="${student.id}" 
                                data-student='${JSON.stringify(student)}' 
                                title="View Details">
                                <i class="bi bi-eye fs-5"></i>
                            </a>
                            <a href="#" class="edit-student-btn text-warning d-inline-flex align-items-center justify-content-center" 
                                style="width: 32px; height: 32px;"
                                data-bs-toggle="modal" 
                                data-bs-target="#editStudentModal" 
                                data-id="${student.id}" 
                                data-student='${JSON.stringify(student)}' 
                                title="Edit Student">
                                <i class="bi bi-pencil-square fs-5"></i>
                            </a>
                            <a href="#" class="delete-student-btn text-danger d-inline-flex align-items-center justify-content-center" 
                                style="width: 32px; height: 32px;"
                                data-bs-toggle="modal" 
                                data-bs-target="#deleteStudentModal" 
                                data-id="${student.id}" 
                                data-name="${student.full_name}" 
                                title="Delete Student">
                                <i class="bi bi-trash fs-5"></i>
                            </a>
                        </div>
                    </td>
                </tr>`;
            });
            html += '</tbody></table></div></div></div>';
            studentsList.innerHTML = html;
        }
    }

    // Real-time search functionality
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchValue = this.value.trim().toLowerCase();
            if (searchValue) {
                if (paginationWrapper) paginationWrapper.style.display = 'none';
                // Filter students by name or ID
                const filtered = allStudentsData.filter(student => {
                    const idMatch = (student.student_id || '').toLowerCase().includes(searchValue);
                    const nameMatch = formatStudentName(student).toLowerCase().includes(searchValue);
                    return idMatch || nameMatch;
                });
                renderStudentTable(filtered);
            } else {
                if (paginationWrapper) paginationWrapper.style.display = '';
                renderStudentTable(allStudentsData);
            }
        });
    }

    // Initial render
    renderSectionButtons();

    // Note: View and Edit student modals are now handled by Bootstrap modal events in manage_students.php

    // Add event listener for delete buttons after rendering
    studentsList.addEventListener('click', function(e) {
        if (e.target.closest('.delete-student-btn')) {
            const btn = e.target.closest('.delete-student-btn');
            const studentId = btn.getAttribute('data-id');
            const studentName = btn.getAttribute('data-name');
            
            // Store the student ID globally
            currentDeleteStudentId = studentId;
            
            // Set modal fields
            const deleteModal = document.getElementById('deleteStudentModal');
            const deleteStudentId = deleteModal.querySelector('#deleteStudentId');
            const deleteStudentName = deleteModal.querySelector('#deleteStudentName');
            
            if (deleteStudentId) {
                deleteStudentId.value = studentId;
            }
            if (deleteStudentName) deleteStudentName.textContent = studentName;
        }
    });

    // Attach delete handler every time the modal is shown
    const deleteStudentModal = document.getElementById('deleteStudentModal');
    if (deleteStudentModal) {
        deleteStudentModal.addEventListener('shown.bs.modal', function () {
            const confirmDeleteBtn = document.getElementById('confirmDeleteStudentBtn');
            if (confirmDeleteBtn) {
                confirmDeleteBtn.onclick = function() {
                    // Try to get student ID from both sources
                    const studentIdFromInput = document.getElementById('deleteStudentId').value;
                    const studentId = studentIdFromInput || currentDeleteStudentId;
                    
                    if (!studentId || studentId === '0' || studentId === 0) {
                        alert('Error: Invalid student ID for deletion');
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append('action', 'delete_student');
                    formData.append('student_id', studentId);
                    fetch('manage_students.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData
                    })
                    .then(response => {
                        // Check if response is JSON
                        const contentType = response.headers.get('content-type');
                        if (contentType && contentType.includes('application/json')) {
                            return response.json();
                        } else {
                            // If it's not JSON (redirect), reload the page
                            window.location.reload();
                            return null;
                        }
                    })
                    .then(data => {
                        if (data) {
                            if (data.success) {
                                const deleteModal = bootstrap.Modal.getInstance(deleteStudentModal);
                                if (deleteModal) deleteModal.hide();
                                // Refresh the student list
                                fetchStudents();
                                // Show success message if available
                                if (data.message) {
                                    // You can show a toast notification here if you have one
                                    console.log('Success:', data.message);
                                }
                            } else {
                                alert('Failed to delete student: ' + (data.error || 'Unknown error'));
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to delete student. Please try again.');
                    });
                };
            }
        });
    }

    // === BEGIN: Edit Modal Validation (gaya ng register.php) ===
    function showEditError(input, message) {
        input.classList.add('is-invalid');
        let error = input.nextElementSibling;
        if (!error || !error.classList.contains('invalid-feedback')) {
            error = document.createElement('div');
            error.className = 'invalid-feedback';
            input.parentNode.appendChild(error);
        }
        error.textContent = message;
        error.style.display = 'block';
    }
    function clearEditError(input) {
        input.classList.remove('is-invalid');
        let error = input.nextElementSibling;
        if (error && error.classList.contains('invalid-feedback')) {
            error.style.display = 'none';
        }
    }

    function validateEditModal() {
        let valid = true;
        // First Name
        const firstName = document.getElementById('editFirstName');
        const firstNameVal = firstName.value.trim();
        if (!firstNameVal) {
            showEditError(firstName, 'First name is required');
            valid = false;
        } else if (!/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/.test(firstNameVal)) {
            showEditError(firstName, 'First name must only contain letters, single spaces, hyphens, and apostrophes.');
            valid = false;
        } else {
            clearEditError(firstName);
        }
        // Middle Name (optional)
        const middleName = document.getElementById('editMiddleName');
        const middleNameVal = middleName.value.trim();
        if (middleNameVal && !/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/.test(middleNameVal)) {
            showEditError(middleName, 'Middle name must only contain letters, single spaces, hyphens, and apostrophes.');
            valid = false;
        } else {
            clearEditError(middleName);
        }
        // Last Name
        const lastName = document.getElementById('editLastName');
        const lastNameVal = lastName.value.trim();
        if (!lastNameVal) {
            showEditError(lastName, 'Last name is required');
            valid = false;
        } else if (!/^[A-Za-z]+([\s\-'][A-Za-z]+)*$/.test(lastNameVal)) {
            showEditError(lastName, 'Last name must only contain letters, single spaces, hyphens, and apostrophes.');
            valid = false;
        } else {
            clearEditError(lastName);
        }
        // Suffix (optional, allow letters only)
        const suffixName = document.getElementById('editSuffixName');
        const suffixNameVal = suffixName.value.trim();
        if (suffixNameVal && !/^[A-Za-z. ]+$/.test(suffixNameVal)) {
            showEditError(suffixName, 'Suffix must only contain letters and periods.');
            valid = false;
        } else {
            clearEditError(suffixName);
        }
        // Place of Birth
        const pob = document.getElementById('editPlaceOfBirth');
        const pobVal = pob.value.trim();
        if (!pobVal) {
            showEditError(pob, 'Place of birth is required');
            valid = false;
        } else if (/[0-9]/.test(pobVal)) {
            showEditError(pob, 'Place of birth must not contain numbers.');
            valid = false;
        } else {
            clearEditError(pob);
        }
        // Citizenship
        const citizenship = document.getElementById('editCitizenship');
        const citizenshipVal = citizenship.value.trim();
        if (!citizenshipVal) {
            showEditError(citizenship, 'Citizenship is required');
            valid = false;
        } else if (!/^[A-Za-z ]+$/.test(citizenshipVal)) {
            showEditError(citizenship, 'Citizenship must only contain letters and spaces.');
            valid = false;
        } else {
            clearEditError(citizenship);
        }
        // Phone Number
        const phone = document.getElementById('editPhone');
        const phoneVal = phone.value.trim();
        if (!phoneVal) {
            showEditError(phone, 'Phone number is required');
            valid = false;
        } else if (!/^09[0-9]{9}$/.test(phoneVal)) {
            showEditError(phone, 'Phone number must be exactly 11 digits and start with 09.');
            valid = false;
        } else {
            clearEditError(phone);
        }
        // Email
        const email = document.getElementById('editEmail');
        const emailVal = email.value.trim();
        if (!emailVal) {
            showEditError(email, 'Email is required');
            valid = false;
        } else if (!/^[^@\s]+@gmail\.com$/i.test(emailVal)) {
            showEditError(email, 'Only Gmail addresses (@gmail.com) are accepted.');
            valid = false;
        } else {
            clearEditError(email);
        }
        // Birthdate
        const birthdate = document.getElementById('editBirthdate');
        const birthdateVal = birthdate.value;
        if (!birthdateVal) {
            showEditError(birthdate, 'Birthdate is required');
            valid = false;
        } else {
            const birth = new Date(birthdateVal);
            const today = new Date();
            const age = today.getFullYear() - birth.getFullYear() - (today < new Date(birth.setFullYear(today.getFullYear())) ? 1 : 0);
            if (birth > today) {
                showEditError(birthdate, 'Birthdate cannot be in the future.');
                valid = false;
            } else if (age < 18 || age > 80) {
                showEditError(birthdate, 'You must be at least 18 years old and not older than 80 years old.');
                valid = false;
            } else {
                clearEditError(birthdate);
            }
        }
        // Address
        const address = document.getElementById('editAddress');
        if (!address.value.trim()) {
            showEditError(address, 'Address is required');
            valid = false;
        } else {
            clearEditError(address);
        }
        // Course
        const course = document.getElementById('editCourse');
        if (!course.value.trim()) {
            showEditError(course, 'Course is required');
            valid = false;
        } else {
            clearEditError(course);
        }
        return valid;
    }

    // Attach validation to edit form submit
    const editStudentModal = document.getElementById('editStudentModal');
    if (editStudentModal) {
        const form = editStudentModal.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (!validateEditModal()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        }
        // Real-time input restrictions (like register.php)
        // First/Middle/Last Name: block numbers/special chars
        ['editFirstName','editMiddleName','editLastName'].forEach(function(id) {
            const field = document.getElementById(id);
            if (field) {
                field.addEventListener('keypress', function(e) {
                    const char = String.fromCharCode(e.which);
                    if (!/[a-zA-Z\s\-']/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                        e.preventDefault();
                    }
                });
                field.addEventListener('input', function(e) {
                    field.value = field.value.replace(/[^A-Za-z\s\-']/g, '');
                });
            }
        });
        // Suffix: allow only letters, period, space
        const suffixField = document.getElementById('editSuffixName');
        if (suffixField) {
            suffixField.addEventListener('input', function(e) {
                suffixField.value = suffixField.value.replace(/[^A-Za-z. ]/g, '');
            });
        }
        // Place of Birth: block numbers
        const pobField = document.getElementById('editPlaceOfBirth');
        if (pobField) {
            pobField.addEventListener('keypress', function(e) {
                const char = String.fromCharCode(e.which);
                if (/[0-9]/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
            });
            pobField.addEventListener('input', function(e) {
                pobField.value = pobField.value.replace(/[0-9]/g, '');
            });
        }
        // Citizenship: allow only letters and spaces
        const citizenshipField = document.getElementById('editCitizenship');
        if (citizenshipField) {
            citizenshipField.addEventListener('keypress', function(e) {
                const char = String.fromCharCode(e.which);
                if (!/[a-zA-Z\s]/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
            });
            citizenshipField.addEventListener('input', function(e) {
                citizenshipField.value = citizenshipField.value.replace(/[^A-Za-z\s]/g, '');
            });
        }
        // Phone: only numbers, max 11 digits
        const phoneField = document.getElementById('editPhone');
        if (phoneField) {
            phoneField.addEventListener('keypress', function(e) {
                const char = String.fromCharCode(e.which);
                if (!/[0-9]/.test(char) && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
                if (phoneField.value.length >= 11 && ![8,9,13,37,39,46].includes(e.keyCode)) {
                    e.preventDefault();
                }
            });
            phoneField.addEventListener('input', function(e) {
                phoneField.value = phoneField.value.replace(/[^0-9]/g, '').slice(0,11);
            });
        }
        // Email: force lowercase
        const emailField = document.getElementById('editEmail');
        if (emailField) {
            emailField.addEventListener('input', function(e) {
                emailField.value = emailField.value.toLowerCase();
            });
        }
        // Birthdate: set max to today
        const birthdateField = document.getElementById('editBirthdate');
        if (birthdateField) {
            const today = new Date().toISOString().split('T')[0];
            birthdateField.setAttribute('max', today);
        }
    }
    // === END: Edit Modal Validation ===
});

// Compose full name with correct format: LastName, FirstName [MiddleNames] [MiddleInitial.] [Suffix]
function formatStudentName(student) {
    const firstName = (student.first_name || '').trim();
    const middleName = (student.middle_name || '').trim();
    const lastName = (student.last_name || '').trim();
    const suffix = (student.suffix_name || '').trim();
    let middleDisplay = '';
    let middleInitial = '';
    if (middleName) {
        const middleParts = middleName.split(/\s+/);
        if (middleParts.length > 1) {
            middleDisplay = middleParts.slice(0, -1).join(' ');
            middleInitial = middleParts[middleParts.length - 1].charAt(0).toUpperCase() + '.';
        } else {
            middleInitial = middleParts[0].charAt(0).toUpperCase() + '.';
        }
    }
    let displayName = lastName;
    if (firstName) displayName += ', ' + firstName;
    if (middleDisplay) displayName += ' ' + middleDisplay;
    if (middleInitial) displayName += ' ' + middleInitial;
    if (suffix) displayName += ' ' + suffix;
    return displayName.trim();
} 